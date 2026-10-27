<?php

namespace Torgash\Banners;

use App\Core\Content\Placeholders;

/**
 * A template's HTML and CSS as the admin typed them (PLAN §51), and the
 * token grammar they use — core's own (`Placeholders::PATTERN`, `[…]` that
 * vanishes with an empty token), so a template reads like an HTML block.
 */
class TemplateText
{
    /** @return list<string> the token names the HTML uses, in order of first use */
    public static function tokens(string $html): array
    {
        preg_match_all(Placeholders::PATTERN, $html, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1] ?? [])));
    }

    /**
     * What is stored of the typed HTML and CSS: a `<style>` written into the
     * HTML moves to the CSS (in the HTML it would be printed again with every
     * slide); a `<script>` is dropped — the shop's own scripts are Snippets,
     * where consent is asked.
     *
     * @return array{0: string, 1: string} html, the css it carried
     */
    public static function clean(string $html): array
    {
        $css = '';

        $html = (string) preg_replace_callback('~<style\b[^>]*>(.*?)</style\s*>~is', function (array $match) use (&$css) {
            $css .= trim($match[1])."\n";

            return '';
        }, $html);

        $html = (string) preg_replace('~<script\b[^>]*>.*?</script\s*>~is', '', $html);
        $html = (string) preg_replace('~<script\b[^>]*>~i', '', $html);

        return [trim($html), trim($css)];
    }

    /** CSS that cannot close the <style> it is printed in. */
    public static function css(?string $css): string
    {
        return (string) preg_replace('~</(style)~i', '<\\/$1', trim((string) $css));
    }
}
