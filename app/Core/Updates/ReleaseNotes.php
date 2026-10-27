<?php

namespace App\Core\Updates;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * What a release says it changed, as a shop's System page shows it.
 *
 * One text serves two readers: the release page on GitHub, which shows it
 * whole, and the shop, which wants it in its admin's language. So the text is
 * Markdown, English first, and each translation follows under a line of its
 * own — `---` — in the order of RELEASE_LOCALES. A shop takes the section in
 * its language (English when there is none), drawn as HTML: raw HTML in the
 * text is stripped and unsafe links dropped, since the text comes from outside
 * the shop, and it still goes through safe_html().
 */
final class ReleaseNotes
{
    /** The order of the sections in a release's text. */
    public const LOCALES = ['en', 'ru'];

    /** The section for $locale, as plain Markdown. */
    public static function section(string $notes, ?string $locale = null): string
    {
        $sections = preg_split('/^\s*-{3,}\s*$/m', trim($notes)) ?: [''];
        $index = array_search($locale ?? app()->getLocale(), self::LOCALES, true);

        return trim((string) ($sections[$index === false ? 0 : $index] ?? $sections[0]));
    }

    public static function html(string $notes, ?string $locale = null): HtmlString
    {
        $markdown = self::section($notes, $locale);

        if ($markdown === '') {
            return new HtmlString('');
        }

        return safe_html(Str::markdown($markdown, ['html_input' => 'strip', 'allow_unsafe_links' => false]));
    }
}
