<?php

namespace Torgash\Age\Http;

use Illuminate\Http\Request;
use Torgash\Age\Rules;
use Torgash\Age\Visitor;

/**
 * "I am 18 or older": the strip's and the window's button (PLAN §71).
 *
 * A POST — a GET would be pressed by a browser's link prefetcher on its own —
 * that writes the age into a cookie and goes back to the page, which is then
 * drawn (or served from the page cache: the cookie is a `vary`) with the
 * goods shown. The older of the two answers is kept: a visitor who said 21
 * on one page is not taken back to 18 by the next. Nothing else is stored:
 * it is the visitor's word, and the shop does not pretend otherwise.
 */
class ConfirmController
{
    public function __invoke(Request $request)
    {
        $age = max(Rules::clampAge($request->input('age')), Visitor::cookieAge($request));

        return redirect()->to($this->back($request))
            ->withCookie(cookie(Visitor::COOKIE, (string) max(1, $age), 60 * 24 * Visitor::DAYS, '/', null, null, true, false, 'lax'));
    }

    /** The page it was pressed on — this shop's own path only, never an address a form could bring. */
    protected function back(Request $request): string
    {
        $back = (string) $request->input('back', '');

        return str_starts_with($back, '/') && ! str_starts_with($back, '//') && ! str_contains($back, '\\')
            ? url($back)
            : url('/');
    }
}
