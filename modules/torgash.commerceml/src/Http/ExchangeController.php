<?php

namespace Torgash\CommerceMl\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Throwable;
use Torgash\Api\Models\ApiToken;
use Torgash\CommerceMl\Import\Runner;
use Torgash\CommerceMl\Models\Exchange;
use Torgash\CommerceMl\Sale\Export;
use Torgash\CommerceMl\Sale\Outbox;

/**
 * The endpoint 1C talks to. One URL, four modes, plain text answers.
 *
 * The protocol is old and blunt, and worth stating because nothing about it is
 * guessable from the code: 1C calls `?type=catalog&mode=checkauth`, gets a
 * cookie, then `mode=init` for the limits, then `mode=file` once per chunk of
 * the upload, then `mode=import` over and over for as long as the shop keeps
 * answering `progress`. Every answer is a bare word on the first line, and a
 * message on the second; anything else — an HTML error page, a redirect, a
 * warning printed by PHP — is read by 1C as a failed exchange with no
 * explanation.
 *
 * Which is why this route is registered OUTSIDE `web`: a session cookie, a CSRF
 * token and a locale prefix are three ways to answer 1C with something it
 * cannot parse. Same reasoning as the payments callback.
 *
 * The slicing is deliberate too. `mode=import` does a batch and answers
 * `progress`, 1C asks again, and the work is spread over as many requests as it
 * takes. No queue worker is involved: the protocol already solved this problem,
 * and a shop whose worker is not running would otherwise see an exchange that
 * says "in progress" for ever.
 */
class ExchangeController extends Controller
{
    public function __invoke(Request $request, Runner $runner)
    {
        $token = $this->authenticate($request);

        if (! $token) {
            return $this->challenge();
        }

        $type = (string) $request->query('type', 'catalog');
        $mode = (string) $request->query('mode', '');

        try {
            return match ($mode) {
                'checkauth' => $this->checkauth($token, $type),
                'init' => $this->init(),
                'file' => $this->file($request),
                'import' => $this->import($request, $runner),
                'query' => $this->query($request),
                'success' => $this->success($request),
                default => $this->answer("failure\n".__('Unknown mode.')),
            };
        } catch (Throwable $e) {
            // 1C shows the second line to whoever pressed the button, so it is
            // the only place a shop will ever read this. Logged in full as well.
            report($e);
            $this->current($request)?->finish('failed', $e->getMessage());

            return $this->answer("failure\n".$e->getMessage());
        }
    }

    // ------------------------------------------------------------------ modes

    /**
     * `success`, the cookie's name, the cookie's value.
     *
     * The value addresses this conversation's own directory for the rest of the
     * exchange, so it is a secret and is minted the way a cart token is. 1C
     * echoes it back on every later request; we accept the Basic header too,
     * because 1C keeps sending that as well and a shop that restarts an
     * exchange should not be told to authenticate twice.
     */
    protected function checkauth(ApiToken $token, string $type): Response
    {
        $exchange = Exchange::create([
            'token' => Exchange::newToken(),
            'user_id' => $token->user_id,
            'kind' => $type === 'sale' ? 'sale' : 'catalog',
            'path' => $this->directory(),
            'state' => 'open',
        ]);

        return $this->answer("success\n".self::COOKIE."\n".$exchange->token);
    }

    /**
     * What the shop can take: whether it can unzip, and the largest chunk.
     *
     * `zip=no` is answered when the extension is missing rather than failing:
     * 1C then sends plain XML, which is slower and entirely correct, and a shop
     * on a host without ext-zip still has a working exchange.
     */
    protected function init(): Response
    {
        $zip = class_exists(\ZipArchive::class) ? 'yes' : 'no';
        $limit = max(65536, (int) setting('torgash.commerceml', 'file_limit', 8388608));

        return $this->answer("zip={$zip}\nfile_limit={$limit}");
    }

    /**
     * One chunk of one file, as the raw request body.
     *
     * APPENDED, never written: a file larger than the announced limit arrives as
     * several requests carrying the same name, and a shop that overwrites each
     * time ends up importing the last few megabytes of somebody's catalogue.
     */
    protected function file(Request $request): Response
    {
        $exchange = $this->current($request);

        if (! $exchange) {
            return $this->answer("failure\n".__('No exchange is open. Call checkauth first.'));
        }

        $name = $this->safeName((string) $request->query('filename', ''));

        if ($name === null) {
            return $this->answer("failure\n".__('That file name is not acceptable.'));
        }

        $path = $exchange->file($name);
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, $request->getContent(), FILE_APPEND);

        if (str_ends_with(strtolower($name), '.zip')) {
            $this->unzip($path, $exchange->path);
        }

        return $this->answer('success');
    }

    /**
     * A slice of the import, and `progress` while there is more.
     *
     * 1C names the file it wants imported; it is one of the ones it has just
     * sent, and the runner keeps the offset on the exchange row so the next
     * call resumes rather than restarts.
     */
    protected function import(Request $request, Runner $runner): Response
    {
        $exchange = $this->current($request);

        if (! $exchange) {
            return $this->answer("failure\n".__('No exchange is open. Call checkauth first.'));
        }

        $name = $this->safeName((string) $request->query('filename', ''));

        if ($name === null || ! is_file($exchange->file($name))) {
            return $this->answer("failure\n".__('No such file was uploaded in this exchange.'));
        }

        // Every stock number this batch writes is 1C's doing (PLAN §42).
        $result = app(\Torgash\Catalog\Stock\StockMoves::class)->because(['type' => '1c'], fn () => $runner->slice($exchange, $name));

        return $this->answer($result['done']
            ? "success\n".$result['message']
            : "progress\n".$result['message']);
    }

    /**
     * The orders, as a CommerceML file. The one mode that answers with XML.
     *
     * Everything else in this protocol replies with a bare word, which is why
     * the content type is set explicitly here and why nothing may be echoed
     * before it: a stray warning in front of the declaration is a file 1C
     * refuses without saying so.
     *
     * The orders are recorded as OFFERED, not delivered — `mode=success` is a
     * separate request, and until it arrives an exchange that died halfway must
     * hand over the same orders again.
     */
    protected function query(Request $request): Response
    {
        $result = app(Export::class)->document();

        $this->current($request)?->forceFill([
            'created_count' => $result['count'],
            'message' => __('Offered :count orders.', ['count' => $result['count']]),
        ])->save();

        return response($result['xml'], 200, ['Content-Type' => 'text/xml; charset=utf-8']);
    }

    /**
     * 1C has taken what it was given.
     *
     * The only place an order stops being re-offered, and the reason the two
     * halves of the handshake are not folded into one: a shop whose 1C dies
     * while reading the file loses nothing.
     */
    protected function success(Request $request): Response
    {
        $exchange = $this->current($request);

        // Only the sale conversation confirms anything. Some 1C versions send
        // `success` at the end of a catalogue exchange too, and treating that
        // as "the orders arrived" would mark orders delivered to a session that
        // never asked for one.
        if ($exchange && $exchange->kind !== 'sale') {
            $exchange->finish('done', $exchange->message);

            return $this->answer('success');
        }

        $confirmed = app(Outbox::class)->confirm();

        $exchange?->finish('done', __('1C confirmed :count orders.', ['count' => $confirmed]));

        return $this->answer('success');
    }

    // ---------------------------------------------------------------- plumbing

    /** The cookie name handed to 1C. Ours, not the framework's — we have no session here. */
    protected const COOKIE = 'torgash_commerceml';

    /**
     * Basic authentication, with an API TOKEN as the password.
     *
     * 1C's own dialog asks for a user and a password, so that is what it will
     * send. What it must not send is a person's actual password: this is a
     * credential that lives in a config file on somebody's accounting machine
     * for years. A token is revocable on its own, carries only the rights its
     * owner has, and the shop already has exactly one place where tokens live —
     * a second one would be a second thing to remember to revoke.
     *
     * The user name is ignored on purpose. 1C insists on one, the token already
     * names its owner, and demanding they agree would only invent a way to fail.
     */
    protected function authenticate(Request $request): ?ApiToken
    {
        $password = $request->getPassword();

        if (! $password) {
            return null;
        }

        $token = ApiToken::with('user')->where('token_hash', ApiToken::hash($password))->first();

        if (! $token || ! $token->user || $token->isExpired() || ! $token->allows('write')) {
            return null;
        }

        // From here on it is an ordinary logged-in user, and every permission
        // check downstream is the admin panel's own.
        auth()->setUser($token->user);

        if (! $token->user->can('commerceml.manage')) {
            return null;
        }

        $token->forceFill(['last_used_at' => now()])->saveQuietly();

        return $token;
    }

    protected function current(Request $request): ?Exchange
    {
        $token = $request->cookie(self::COOKIE) ?: $request->header('X-Exchange');

        return $token
            ? Exchange::where('token', $token)->where('state', 'open')->first()
            : Exchange::where('state', 'open')->latest('id')->first();
    }

    /**
     * A file name that cannot leave the exchange's directory.
     *
     * 1C legitimately sends a path — `import_files/8f/photo.jpg` — so the
     * separator cannot simply be banned. What is banned is any `..` segment and
     * any absolute root, checked on the SEGMENTS rather than by stripping
     * substrings, because stripping is the version people get wrong.
     */
    protected function safeName(string $name): ?string
    {
        $name = str_replace('\\', '/', trim($name));

        if ($name === '' || str_starts_with($name, '/') || preg_match('~^[a-zA-Z]:~', $name)) {
            return null;
        }

        foreach (explode('/', $name) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, "\0")) {
                return null;
            }
        }

        return $name;
    }

    protected function unzip(string $archive, string $into): void
    {
        $zip = new \ZipArchive();

        if ($zip->open($archive) !== true) {
            return;
        }

        // Entry names get the same treatment as an uploaded name: a zip is the
        // classic way to write outside the directory you were given.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $safe = $this->safeName($entry);

            if ($safe === null || str_ends_with($entry, '/')) {
                continue;
            }

            $target = $into.DIRECTORY_SEPARATOR.$safe;
            File::ensureDirectoryExists(dirname($target));
            copy("zip://{$archive}#{$entry}", $target);
        }

        $zip->close();
        @unlink($archive);
    }

    protected function directory(): string
    {
        $path = storage_path('app/commerceml/'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)));
        File::ensureDirectoryExists($path);

        return $path;
    }

    /**
     * Plain text, UTF-8, and nothing else in the body.
     *
     * Explicitly typed because a shop behind a proxy that "helpfully" wraps
     * text/html is a shop whose exchange stops working for no visible reason.
     */
    protected function answer(string $body): Response
    {
        return response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    protected function challenge(): Response
    {
        return response(__('Enter the exchange login and token.'), 401, [
            'WWW-Authenticate' => 'Basic realm="CommerceML"',
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
