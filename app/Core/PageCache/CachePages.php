<?php

namespace App\Core\PageCache;

use App\Core\Http\Csp;
use App\Core\Support\FormGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cookie;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * `page.cache:<kind>,<param>,…` on a storefront route (PLAN §61): the page is
 * served from a copy when there is one for this visitor, and a copy is taken
 * when there is not and this visitor may give one.
 *
 * The parameters after the kind are the address's parameters the page
 * reads (`page`, `sort`); with any other the page is simply drawn. Runs
 * after the `web` group — the session, the storefront, the theme and the
 * language are all decided by then, the same way for a copy as for a page.
 *
 * Every answer says what happened in `X-Torgash-Cache`: `hit`, `stored`,
 * `miss` (drawn, not kept) or `bypass` (not a page a copy is for) — with
 * the reason beside it while APP_DEBUG is on.
 */
class CachePages
{
    public function __construct(protected PageCache $cache, protected Holes $holes) {}

    public function handle(Request $request, Closure $next, string $kind = 'page', string ...$params): SymfonyResponse
    {
        if (! $this->cache->enabled() || $this->cache->skips($kind)) {
            return $next($request);
        }

        if (($reason = $this->cache->bypassReason($request, $params)) !== null) {
            // A key nobody declared is a module that forgot to — worth a line
            // on the cache screen; a signed-in customer is not.
            if (str_starts_with($reason, 'session holds ') || str_starts_with($reason, 'holds the cookie ')) {
                $this->cache->noteRefusal($kind, $request->url(), $reason);
            }

            return $this->marked($next($request), 'bypass', $reason);
        }

        $address = $this->cache->address($request, $params);

        try {
            $entry = $this->cache->find($address, $this->cache->varyValues($request));
        } catch (Throwable $e) {
            report($e);
            $entry = null;
        }

        if ($entry !== null) {
            try {
                return $this->serve($entry, $request);
            } catch (Throwable $e) {
                // A copy that cannot be served is drawn again — and not kept
                // for the next visitor to fail on.
                report($e);
                $this->cache->forgetEntry($entry['id']);
            }
        }

        if (($reason = $this->cache->takeRefusal($request)) !== null) {
            return $this->marked($next($request), 'miss', $reason);
        }

        return $this->take($request, $next, $kind, $address);
    }

    /** @param array{id: int, body: string, meta: array<string, mixed>} $entry */
    protected function serve(array $entry, Request $request): SymfonyResponse
    {
        $meta = $entry['meta'];
        $body = gzinflate($entry['body']);

        if ($body === false) {
            throw new RuntimeException('A kept page could not be inflated.');
        }

        $mark = fn (string $name) => 'pc-'.$meta['id'].'-'.$name;

        // What the page did for every visit, before its personal parts are
        // drawn — as the page itself does them before its view.
        foreach ($meta['visits'] ?? [] as [$name, $data]) {
            $this->cache->runVisit($name, $data);
        }

        $private = [
            $mark('token') => (string) $request->session()->token(),
            $mark('nonce') => app(Csp::class)->nonce(),
        ];

        // Drawn before the stamp is asked for: FormGuard hands a part being
        // drawn now a stamp of its own.
        $holes = (array) ($meta['holes'] ?? []);
        $session = $request->session()->all();
        $kept = $this->cache->personalHeld($request) === null
            ? array_filter($holes, fn (array $hole) => $this->asDrawn($hole, $session, $request))
            : [];
        $parts = $this->holes->drawAll(array_diff_key($holes, $kept));

        if (str_contains($body, $mark('stamp')) || $kept !== []) {
            $private[$mark('stamp')] = app(FormGuard::class)->stamp();
        }

        foreach ($kept as $n => $hole) {
            $parts[$n] = strtr((string) $hole['html'], $private);
        }

        $fresh = $private;

        foreach ($parts as $n => $html) {
            $fresh['<!--'.$mark('h'.$n).'-->'] = $html;
        }

        return $this->marked(new Response(strtr($body, $fresh), 200, (array) ($meta['headers'] ?? [])), 'hit');
    }

    /**
     * May this visitor have the part as it was drawn? When it depends on the
     * session alone and they hold none of the personal keys it read — and no
     * personal cookie, whose reads nothing sees.
     *
     * @param  array<string, mixed>  $hole
     */
    protected function asDrawn(array $hole, array $session, Request $request): bool
    {
        if (! isset($hole['html'], $hole['keys'])) {
            return false;
        }

        foreach ((array) $hole['keys'] as $key => $seen) {
            $key = (string) $key;

            if (Capture::seen(Arr::has($session, $key), Arr::get($session, $key)) !== $seen) {
                return false;
            }
        }

        foreach ($request->cookies->all() as $name => $value) {
            if ($value !== null && PageCache::isPersonal($this->cache->cookieRole((string) $name))) {
                return false;
            }
        }

        return true;
    }

    protected function take(Request $request, Closure $next, string $kind, string $address): SymfonyResponse
    {
        $session = $request->session();

        if (! $session instanceof WatchedStore && ! $session instanceof WatchedEncryptedStore) {
            return $this->marked($next($request), 'miss', 'the session cannot be watched');
        }

        try {
            $counters = $this->cache->counters();
        } catch (Throwable $e) {
            report($e);

            return $this->marked($next($request), 'miss', 'no table');
        }

        $capture = new Capture($this->cache, $kind, $address, (string) $session->token(), $counters);
        $queued = count(Cookie::getQueuedCookies());
        $session->watch($capture);
        $this->cache->begin($capture);

        try {
            $response = $next($request);
        } finally {
            $this->cache->end();
            $session->watch(null);
        }

        if (count(Cookie::getQueuedCookies()) > $queued) {
            $capture->refuse('sets a cookie');
        }

        return $this->keep($capture, $request, $response);
    }

    protected function keep(Capture $capture, Request $request, SymfonyResponse $response): SymfonyResponse
    {
        try {
            if (($next = $this->cache->nextChange()) !== null) {
                $capture->until($next);
            }
        } catch (Throwable $e) {
            report($e);
            $capture->refuse('cannot tell when what it shows changes by itself');
        }

        $refusal = $capture->refusal() ?? $this->unfit($response) ?? ($capture->stale() ? 'changes before it was drawn' : null);
        $html = $refusal === null ? (string) $response->getContent() : '';
        $cut = $refusal === null ? $capture->cut($html, app(Csp::class)->nonce()) : null;

        if ($cut === null) {
            $refusal ??= $capture->refusal() ?? 'not cut';

            // A 404 or a redirect is what the address IS, not something to fix.
            if ($response->getStatusCode() === 200) {
                $this->cache->noteRefusal($capture->kind, $capture->address, $refusal);
            }

            if ($this->plain($response) && str_contains((string) $response->getContent(), 'pc-'.$capture->id.'-')) {
                $response->setContent($this->forVisitor($capture, (string) $response->getContent()));
            }

            return $this->marked($response, 'miss', $refusal);
        }

        $headers = $response->headers->allPreserveCaseWithoutCookies();
        unset($headers['Date'], $headers['Content-Length'], $headers['X-Torgash-Cache']);

        try {
            $stored = $this->cache->store(
                $capture->address,
                $capture->kind,
                $this->cache->varyValues($request),
                $cut['stored'],
                [
                    'id' => $capture->id,
                    'headers' => $headers,
                    'holes' => $capture->holes(),
                    'visits' => $capture->visits(),
                ],
                $capture->tags(),
                $capture->expires($this->cache->minutes()),
                $capture->counters['generation'],
                $capture->counters['changes'],
            );
        } catch (Throwable $e) {
            report($e);
            $stored = false;
        }

        $response->setContent($this->forVisitor($capture, $cut['visitor']));

        return $this->marked($response, $stored ? 'stored' : 'miss', $stored ? null : 'changed while drawn');
    }

    /** A response whose body is a string in memory — not a file or a stream. */
    protected function plain(SymfonyResponse $response): bool
    {
        return ! $response instanceof StreamedResponse && ! $response instanceof BinaryFileResponse;
    }

    /** Why a response is not a page to keep, or null. */
    protected function unfit(SymfonyResponse $response): ?string
    {
        if (! $response instanceof Response && $response::class !== SymfonyResponse::class) {
            return 'not a plain page';
        }

        if ($response->getStatusCode() !== 200) {
            return 'answered '.$response->getStatusCode();
        }

        if (! str_starts_with(strtolower((string) $response->headers->get('Content-Type')), 'text/html')) {
            return 'not HTML';
        }

        if ($response->headers->getCookies() !== []) {
            return 'sets a cookie';
        }

        if ($response->headers->hasCacheControlDirective('no-store')) {
            return 'asks not to be stored';
        }

        return null;
    }

    /** The drawing visitor's own page: markers out, a real form stamp in. */
    protected function forVisitor(Capture $capture, string $html): string
    {
        $html = preg_replace('/<!--\/?'.preg_quote('pc-'.$capture->id.'-h', '/').'\d+-->/', '', $html) ?? $html;

        return str_contains($html, $capture->placeholder('stamp'))
            ? str_replace($capture->placeholder('stamp'), app(FormGuard::class)->stamp(), $html)
            : $html;
    }

    protected function marked(SymfonyResponse $response, string $state, ?string $reason = null): SymfonyResponse
    {
        $response->headers->set('X-Torgash-Cache', $reason !== null && config('app.debug') ? $state.'; '.$reason : $state);

        return $response;
    }
}
