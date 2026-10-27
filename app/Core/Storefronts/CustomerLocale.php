<?php

namespace App\Core\Storefronts;

use App\Core\Seo\Alternates;
use App\Models\Storefront;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The language a signed-in customer reads the shop in, kept on the account
 * (`users.locale`) so it follows them to every device and into their letters
 * (User::preferredLocale, a newsletter campaign's deliveries).
 *
 * A language is in the ADDRESS (`/ru/…`), so there is no "switch" request to
 * catch. What counts as a switch is moving from one language to another
 * within a session — the session remembers the last page's language
 * (ResolveStorefront). A fresh session landing on the bare domain is not a
 * choice and changes nothing; an account that never had a language takes the
 * first one it is seen in.
 *
 * After signing in (`Login`, whatever door: password, code, checkout,
 * registration) the next ordinary page follows the remembered language —
 * once, so the customer can still switch away on this device.
 */
class CustomerLocale
{
    public const FOLLOW = 'torgash.locale_follow';

    /** Marked when anybody signs in; acted on by the next storefront page. */
    public static function signedIn(): void
    {
        if (app()->bound('session.store') && session()->isStarted()) {
            session()->put(self::FOLLOW, true);
        }
    }

    /** The account's language after sign-in, or what the customer just switched to. */
    public static function handle(Request $request, Storefront $storefront, string $locale, ?string $previous): ?RedirectResponse
    {
        $user = $request->user();

        if (! $user || $request->is('admin', 'admin/*')) {
            return null;
        }

        if ($request->session()->pull(self::FOLLOW)
            && $request->isMethod('GET') && ! $request->header('HX-Request') && ! $request->expectsJson()
            && $user->locale && $user->locale !== $locale
            && in_array($user->locale, $storefront->locales ?? [], true)) {
            $target = Alternates::forUrl($request->fullUrl(), $storefront->locales, $storefront->default_locale)[$user->locale] ?? null;

            if ($target) {
                return redirect()->to($target);
            }
        }

        if ($user->locale !== $locale && ($user->locale === null || ($previous !== null && $previous !== $locale))) {
            // Quietly: a language is not an edit of the account worth an audit line or an event.
            $user->forceFill(['locale' => $locale])->saveQuietly();
        }

        return null;
    }
}
