<?php

namespace App\Core\Seo;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Where an address used to point.
 *
 * Two doors write into it. A module records a rename as it happens — a product
 * slug changed, a page moved to another section — so that nobody's bookmark
 * 404s. And a person types or imports a list, which is how a shop arrives from
 * another engine: the first thing a migration produces is a file of "old
 * address → new address", and without somewhere to put it every link the old
 * shop ever earned is lost on the day of the move.
 *
 * Chains are collapsed on the way in (A→B→C becomes A→C, B→C) because search
 * engines penalise long redirect chains, and a rename of a page that had
 * already been renamed is the ordinary way one appears.
 *
 * Nothing here decides WHEN a redirect is served. A module that owns an address
 * space asks on its own 404 (the catalogue does); core answers for everything
 * else from the exception handler, and for the one shape that never reaches a
 * 404 — a front controller with a query string, which is what the old shop's
 * addresses look like — from the NormaliseUrl middleware, before routing.
 */
class RedirectManager
{
    /** Who wrote a row — see the migration that added `source`. */
    public const SOURCES = ['manual', 'auto', 'import'];

    /** Inside withoutRecording(): renames leave no redirect. */
    protected bool $paused = false;

    /**
     * Run $work with renames leaving no redirect — a script's bulk edit whose
     * in-between addresses nobody should be sent to. The shop-wide switch is
     * the core setting `redirect_on_rename`. Put back in `finally`: this is a
     * singleton, and a persistent worker serves the next request with it.
     */
    public function withoutRecording(callable $work): mixed
    {
        $was = $this->paused;
        $this->paused = true;

        try {
            return $work();
        } finally {
            $this->paused = $was;
        }
    }

    public function recordChange(string $oldPath, string $newPath, string $source = 'auto'): void
    {
        if ($oldPath === $newPath) {
            return;
        }

        if ($source === 'auto' && ($this->paused || ! setting('core', 'redirect_on_rename', true))) {
            return;
        }

        DB::transaction(function () use ($oldPath, $newPath, $source) {
            // The new path is live content now; it must not also redirect elsewhere.
            DB::table('redirects')->where('from_path', $newPath)->delete();

            // Collapse chains: anything that used to redirect to the old
            // path should now redirect straight to the new one.
            DB::table('redirects')->where('to_path', $oldPath)->update(['to_path' => $newPath]);

            DB::table('redirects')->updateOrInsert(
                ['from_path' => $oldPath],
                ['to_path' => $newPath, 'source' => $source, 'updated_at' => now(), 'created_at' => now()],
            );
        });
    }

    /**
     * Where this address leads now — asked only when a redirect is about to be
     * SERVED, which is why a hit is counted here: one small write, on the
     * request that used the row, and nothing on a request that did not.
     */
    public function resolve(string $path): ?string
    {
        $row = DB::table('redirects')->where('from_path', $path)->first(['id', 'to_path']);

        if ($row === null) {
            return null;
        }

        DB::table('redirects')->where('id', $row->id)->update([
            'hits' => DB::raw('hits + 1'),
            'last_hit_at' => now(),
        ]);

        return $row->to_path;
    }

    /**
     * The target for a whole request, or null — the one place that knows how an
     * incoming address is looked up.
     *
     * The query string FIRST, because an address from another engine is usually
     * all query string (`/index.php?dispatch=products.view&product_id=12`) and
     * the same path without it is a different page. `/index.php` is tried as
     * well as the path the framework reports: a front controller is stripped
     * before anything in PHP sees it, so a row copied out of the old shop's
     * address bar would otherwise never match.
     */
    public function resolveRequest(Request $request): ?string
    {
        $path = '/'.ltrim($request->getPathInfo(), '/');
        $query = $request->getQueryString();

        $candidates = $query === null ? [] : [$path.'?'.$query];

        if ($query !== null && $path === '/') {
            $candidates[] = '/index.php?'.$query;
        }

        $candidates[] = $path;

        foreach ($candidates as $candidate) {
            if ($target = $this->resolve($candidate)) {
                return $target;
            }
        }

        return null;
    }

    /**
     * Where to actually SEND this request, as an absolute address.
     *
     * Absolute on purpose, and it is not decoration: the addresses this exists
     * for arrive AT the front controller (`/index.php?dispatch=…`), so the
     * framework's base path is `/index.php` and a relative target comes back out
     * as `/index.php/product/x`. Building from the request's own scheme and host
     * sidesteps the base path entirely.
     */
    public function redirectFor(Request $request): ?string
    {
        $target = $this->resolveRequest($request);

        if ($target === null) {
            return null;
        }

        return str_starts_with($target, '/')
            ? $request->getSchemeAndHttpHost().$target
            : $target;
    }

    /**
     * One row, as a person typed it.
     *
     * Refuses only what cannot work: an empty side, and an address that points
     * at itself (a browser follows that until it gives up). Everything else is
     * the shop's business — the destination may legitimately be another site.
     *
     * @return bool false when the pair was refused
     */
    /**
     * Automatic rows nobody has arrived through for the core setting
     * `redirect_forget_months` (12; 0 keeps everything) — counted from the
     * last visit, or from when the row was made if none came. Only `auto`:
     * a typed or imported row is the shop's decision, and an old shop's
     * addresses are linked from elsewhere for years. Daily, by the scheduler.
     *
     * @return int rows removed
     */
    public function prune(): int
    {
        $months = (int) setting('core', 'redirect_forget_months', 12);

        if ($months <= 0) {
            return 0;
        }

        return DB::table('redirects')->where('source', 'auto')
            ->whereRaw('coalesce(last_hit_at, updated_at, created_at) < ?', [now()->subMonths($months)])
            ->delete();
    }

    public function put(string $from, string $to, string $source = 'manual'): bool
    {
        $from = $this->normalise($from);
        $to = $this->normalise($to, external: true);

        if ($from === '' || $to === '' || $from === $to) {
            return false;
        }

        $this->recordChange($from, $to, $source);

        return true;
    }

    public function forget(int $id): void
    {
        DB::table('redirects')->where('id', $id)->delete();
    }

    /**
     * A file of pairs — what a migration from another engine hands over.
     *
     * Comma or semicolon separated, one pair per line, a header row ignored if
     * it looks like one. Counted rather than reported line by line: the answer
     * a person wants from an import of nine thousand rows is "how many, and how
     * many were refused", and the refused ones are refused for one of two
     * reasons this method cannot do anything about.
     *
     * @return array{imported: int, refused: int}
     */
    public function import(string $csv): array
    {
        $imported = 0;
        $refused = 0;

        foreach (preg_split('/\R/', $csv) ?: [] as $index => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $columns = str_getcsv($line, str_contains($line, ';') ? ';' : ',');
            $from = trim((string) ($columns[0] ?? ''));
            $to = trim((string) ($columns[1] ?? ''));

            // A header, and only on the first line: "from" is not an address,
            // but a shop may well have a page called /to.
            if ($index === 0 && ! str_contains($from, '/') && ! str_contains($to, '/')) {
                continue;
            }

            $this->put($from, $to, 'import') ? $imported++ : $refused++;
        }

        return ['imported' => $imported, 'refused' => $refused];
    }

    /**
     * What gets stored: a path beginning with one slash.
     *
     * A person pastes what is in the browser's address bar, so a full URL of
     * this shop's own is reduced to its path — storing the host would mean the
     * row never matches. A destination may keep its host (`external`), because
     * "this page now lives on another site" is a real answer.
     */
    public function normalise(string $address, bool $external = false): string
    {
        $address = trim($address);

        if ($address === '') {
            return '';
        }

        $host = parse_url($address, PHP_URL_HOST);

        if ($host !== null) {
            if ($external && $host !== request()?->getHost()) {
                return $address;
            }

            $address = (string) parse_url($address, PHP_URL_PATH)
                .(($query = parse_url($address, PHP_URL_QUERY)) ? '?'.$query : '');
        }

        $address = '/'.ltrim($address, '/');

        // The query is sorted, on both sides of the lookup: `?a=1&b=2` and
        // `?b=2&a=1` are one page, and a file exported from another engine has
        // them in whatever order that engine wrote them. Symfony's own
        // normalisation, so what is stored is exactly what getQueryString()
        // will hand back when the request arrives.
        if (str_contains($address, '?')) {
            [$path, $query] = explode('?', $address, 2);
            $query = Request::normalizeQueryString($query);
            $address = $path.($query === '' ? '' : '?'.$query);
        }

        return $address;
    }
}
