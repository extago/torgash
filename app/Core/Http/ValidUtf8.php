<?php

namespace App\Core\Http;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bytes that are not UTF-8 never reach the database.
 *
 * Postgres refuses them outright ("invalid byte sequence for encoding UTF8"),
 * so `/search?q=%EE%F3` — a link from an old cp1251 site, a scanner, a typo in
 * somebody's script — was a 500 on the search page, and would be on any page
 * whose parameter meets a query. One place for it, before routing, rather than
 * a check in each controller that one of them forgets.
 *
 * A query parameter or form field that is not UTF-8 is SCRUBBED (each invalid
 * sequence becomes `?`): the page still answers, a search for garbage finds
 * nothing, and nobody's filter link breaks because one parameter of five was
 * mangled on the way. A PATH that is not UTF-8 never gets here — the
 * framework's own ValidatePathEncoding answers it 400 first.
 */
class ValidUtf8
{
    public function handle(Request $request, Closure $next): Response
    {
        foreach ([$request->query, $request->request] as $bag) {
            $values = $bag->all();

            if (! self::isClean($values)) {
                $bag->replace(self::scrub($values));
            }
        }

        return $next($request);
    }

    protected static function isClean(array $values): bool
    {
        foreach ($values as $key => $value) {
            if ((is_string($key) && ! mb_check_encoding($key, 'UTF-8'))
                || (is_string($value) && ! mb_check_encoding($value, 'UTF-8'))
                || (is_array($value) && ! self::isClean($value))) {
                return false;
            }
        }

        return true;
    }

    /** @return array<mixed> */
    protected static function scrub(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            $key = is_string($key) ? mb_scrub($key, 'UTF-8') : $key;
            $clean[$key] = match (true) {
                is_string($value) => mb_scrub($value, 'UTF-8'),
                is_array($value) => self::scrub($value),
                default => $value,
            };
        }

        return $clean;
    }
}
