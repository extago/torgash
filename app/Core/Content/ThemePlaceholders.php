<?php

namespace App\Core\Content;

use Illuminate\Support\Facades\Blade;

/**
 * Tokens for HTML a shop writes into its theme's blocks — the footer's
 * signature is the case they were made for: "{logo_dark} {storefront} ©
 * 2015 – {year}", without a block type of its own for one line of markup.
 *
 *   {year}       this year, so a © never goes stale
 *   {logo}       the theme's logo (or the shop's name) on the page's own
 *                colours: the light one, and in the dark scheme the one for a
 *                dark background
 *   {logo_dark}  the logo for a dark background — the dark footer, the dark
 *                header
 *
 * In a band whose zones say `'place' => 'footer'` (the theme's footer) both
 * are the footer's own logo when the theme has one (<x-theme.logo>).
 */
class ThemePlaceholders
{
    public function register(Placeholders $placeholders): void
    {
        $placeholders->register('year', [
            'label' => 'This year',
            'group' => 'shop',
            'resolve' => fn () => date('Y'),
            'example' => date('Y'),
        ]);

        foreach (['logo' => false, 'logo_dark' => true] as $token => $onDark) {
            $placeholders->register($token, [
                'label' => $onDark ? 'Logo for a dark background' : 'Logo',
                'group' => 'shop',
                // Markup by definition — the registry escapes everything else.
                'raw' => true,
                'resolve' => fn (array $context = []) => trim(Blade::render(
                    '<x-theme.logo :on-dark="$onDark" :place="$place" height="h-10" class="text-xl font-bold" />',
                    ['onDark' => $onDark, 'place' => ($context['place'] ?? null) === 'footer' ? 'footer' : null],
                )),
            ]);
        }
    }
}
