<?php

namespace App\Core\Support;

use Illuminate\Http\Request;

/**
 * The storefront without what the SHOP added to it: the engine's own theme in
 * place of the storefront's, no "Your own CSS", no code snippets. Those three
 * are where an edit made on a live shop can break every page at once — a
 * theme's template, a stylesheet that hides the body, a pasted script that
 * redirects or never closes a tag — and none of them can be repaired from a
 * page that does not open.
 *
 * Two ways in. A member of staff turns it on for THEMSELVES (a flag in their
 * session, from Admin → System or a storefront's form): they see what the
 * engine draws, customers see the shop as it is. `TORGASH_SAFE_MODE=true` in
 * .env turns it on for everybody, for when the shop is broken for its
 * customers and the fix will take longer than an hour.
 *
 * Whoever owns one of the three asks `safe_mode()` and leaves its part out:
 * core for the theme and the CSS, `torgash.snippets` for snippets. A module
 * printing other code the shop typed does the same.
 *
 * Stateless — asked a few times a page, each time from the session and the
 * config — so there is nothing to outlive a request.
 */
class SafeMode
{
    public const SESSION = 'safe_mode';

    /** The theme a storefront wears in safe mode: the one that ships with the engine. */
    public const THEME = 'default';

    /** On for everybody, by .env. */
    public static function forced(): bool
    {
        return (bool) config('torgash.safe_mode');
    }

    public static function active(?Request $request = null): bool
    {
        if (self::forced()) {
            return true;
        }

        $request ??= request();

        // The session flag first: it is set for almost nobody, and asking it
        // costs nothing, while asking about staff loads the visitor's roles.
        return $request->hasSession()
            && $request->session()->get(self::SESSION) === true
            && (bool) $request->user()?->isStaff();
    }
}
