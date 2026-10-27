<?php

namespace App\Core\Content;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * A text the shop's staff wrote, made safe to print as HTML on the storefront.
 *
 * The right to edit products is not the right to run scripts on the shop
 * (PLAN §50.3). Without this, anybody with `catalog.manage` could put a
 * script into a description, and the storefront and the admin share one
 * origin and one session: the script would run as the owner the moment they
 * looked at that product and could make itself an administrator. Scripts and
 * the code a shop does want on its pages have one door, Code snippets, and a
 * permission of their own.
 *
 * What a text keeps: everything a description, a page or an article is made
 * of — headings, lists, tables, links, pictures, figures, `details`, video
 * and audio, `class` and `style` for an HTML block's own looks — and frames
 * from the sites named in Settings → Core → Content (a video, a map). What it
 * loses: `<script>`, `<style>`, forms, every `on…` handler, `javascript:`
 * links, and every attribute nobody listed — which is also what removes the
 * Alpine (`x-data`, `@click`) and HTMX (`hx-on`, `hx-get`) attributes that
 * would otherwise run on a page that loads both.
 *
 * Cleaned when PRINTED rather than when saved: an import, the API, 1C and a
 * module writing straight to a table all reach the same columns, and every
 * text already stored is covered the day this ships. Placeholders are
 * expanded AFTER cleaning, since what they produce (`{form:…}`, a product
 * card) is the engine's own markup.
 */
class SafeHtml
{
    protected ?HtmlSanitizer $sanitizer = null;

    /** The sites a frame inside a text may come from, as typed. Hook: `core.content.frame_hosts`. */
    public const SETTING = 'content_frame_hosts';

    public function clean(?string $html): string
    {
        $html = (string) $html;

        // Nothing that could be markup: nothing to do, and no parser to wake.
        if (! str_contains($html, '<')) {
            return $html;
        }

        $clean = $this->sanitizer()->sanitize($html);

        // A frame whose address was refused is an empty box on the page.
        $clean = (string) preg_replace('~<iframe\b(?![^>]*\ssrc=)[^>]*>\s*</iframe>~i', '', $clean);

        // What a module knows about the pictures in it — the media module
        // gives its own their width and height, so the text does not jump
        // as they load. After cleaning: what a listener adds is the engine's.
        return str_contains($clean, '<img') ? (string) hook_filter('core.content.cleaned', $clean) : $clean;
    }

    /**
     * The hosts a frame may come from: the shop's own list, plus whatever a
     * module adds. A host covers its subdomains (`google.com` takes
     * `www.google.com/maps/embed`).
     *
     * @return list<string>
     */
    public static function frameHosts(): array
    {
        $typed = preg_split('/[\s,]+/u', (string) setting('core', self::SETTING, ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $hosts = array_map(
            fn (string $host) => strtolower(trim((string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $host), "/ \t")),
            $typed,
        );

        return array_values(array_unique(array_filter((array) hook_filter('core.content.frame_hosts', $hosts))));
    }

    protected function sanitizer(): HtmlSanitizer
    {
        if ($this->sanitizer) {
            return $this->sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            ->allowSafeElements()
            ->allowElement('iframe', ['src', 'width', 'height', 'title', 'allow', 'allowfullscreen', 'loading', 'referrerpolicy', 'frameborder'])
            ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias()
            ->withAttributeSanitizer(new FrameSource(self::frameHosts()))
            // A long article is not an attack; the parser is.
            ->withMaxInputLength(-1);

        // Looks, on anything; and the few marks the editor itself writes.
        foreach (['class', 'style', 'aria-label', 'aria-hidden'] as $attribute) {
            $config = $config->allowAttribute($attribute, '*');
        }

        $config = $config
            ->allowElement('div', $this->attributesOf($config, 'div', ['data-gallery', 'data-html-block']))
            ->allowElement('img', $this->attributesOf($config, 'img', ['data-size']));

        return $this->sanitizer = new HtmlSanitizer($config);
    }

    /**
     * An element's allowed attributes plus some more — `allowElement()`
     * replaces the list, and `allowAttribute()` takes the attribute OFF every
     * element it does not name.
     *
     * @param  list<string>  $more
     * @return list<string>
     */
    protected function attributesOf(HtmlSanitizerConfig $config, string $element, array $more): array
    {
        return array_values(array_unique([...array_keys($config->getAllowedElements()[$element] ?? []), ...$more]));
    }
}
