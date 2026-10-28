<?php

namespace Torgash\Landings;

use App\Core\Content\Placeholders;
use App\Core\Content\SafeHtml;
use Illuminate\Support\Facades\Storage;
use Torgash\Catalog\Models\Product;
use Torgash\Landings\Models\Landing;

/**
 * One landing as the page prints it.
 *
 * - The HTML is the visitor's language (the base one when that has none),
 *   CLEANED when printed like everything staff write (no scripts, no `on…`,
 *   no forms — App\Core\Content\SafeHtml), its `assets/…` paths pointed at
 *   the landing's own folder, and the product's placeholders ({product_name},
 *   {price}, {feature:…}) filled in AFTER cleaning, as everywhere.
 * - It stands in its own SHADOW ROOT, declared in the HTML
 *   (`<template shadowrootmode="open">`): the shop's stylesheet cannot reach
 *   in and the landing's CSS cannot leak out. `@scope` alone was tried and
 *   was not enough — it keeps the landing's rules in, but daisyUI's own
 *   `.hero`, `.card`, `.btn` still styled a designer's elements of the same
 *   name (a `.hero` became a grid that piled its children on top of each
 *   other). Inherited things — the page's font and text colour — still flow
 *   in, which is what a landing wants. `:scope` and `:root` in the CSS mean
 *   the landing itself, as `:host` does: neither names anything inside a
 *   shadow root, and a designer's variables on `:root` reached nothing.
 *   `@font-face` does not work inside a shadow root, so it is printed beside
 *   it; `@import` is dropped (a landing brings its fonts as files).
 * - A browser too old for declarative shadow roots, and a fragment swapped in
 *   by htmx (which does not parse them), get them attached by one small
 *   script, printed once per page.
 * - The same script takes a landing's own "#…" links there: a browser looks
 *   for a fragment in the document only, never in a shadow root, so on its
 *   own it changed the address and stayed put. A folded landing is asked to
 *   open first (`torgash:unfold`, catalog's fold partial).
 */
class Renderer
{
    public const SPACE_TOP = ['none' => '', 'small' => 'mt-4', 'large' => 'mt-10'];

    public const SPACE_BOTTOM = ['none' => '', 'small' => 'mb-4', 'large' => 'mb-10'];

    /**
     * What every landing's root begins with, in a layer of its own so that
     * any rule the landing writes wins: an element a "#…" link jumps to stops
     * below the page's sticky header, as the product page's sections do.
     */
    protected const BASE_CSS = '@layer torgash{[id]{scroll-margin-top:calc(var(--torgash-header-h,0px) + 4rem)}}';

    protected bool $scriptPrinted = false;

    public function render(Landing $landing, Product $product): string
    {
        $html = trim((string) $landing->t('html'));

        if ($html === '') {
            return '';
        }

        $html = app(SafeHtml::class)->clean($html);
        $html = $this->assets($html, $landing);
        $html = app(Placeholders::class)->expand($html, ['product' => $product]);

        [$outside, $inside] = $this->css($landing);
        $space = trim((self::SPACE_TOP[$landing->space_top] ?? '').' '.(self::SPACE_BOTTOM[$landing->space_bottom] ?? ''));

        $body = '<div class="torgash-landing" data-landing="'.$landing->id.'"><template shadowrootmode="open">'
            .'<style>'.self::BASE_CSS.$inside.'</style>'
            .$html.'</template></div>';

        // Folded as the description is (the catalogue's partial and height),
        // and the space around it stays OUTSIDE the fold, so a folded landing
        // keeps the gap below its button rather than hiding it.
        $height = max(0, (int) setting('torgash.catalog', 'description_fold_height', 420));
        if ($landing->fold && $landing->placement !== 'tab' && $height > 0) {
            $body = view('torgash.catalog::partials.fold', [
                'foldHtml' => $body,
                'foldHeight' => $height,
                'foldMore' => __('Read in full'),
                'foldLess' => __('Fold back'),
            ])->render();
        }

        return ($outside !== '' ? '<style data-landing-fonts="'.$landing->id.'">'.$outside.'</style>' : '')
            .($space !== '' ? '<div class="'.$space.'">'.$body.'</div>' : $body)
            .$this->script();
    }

    /**
     * The landing's CSS: [what goes in the document (@font-face), what goes in
     * its shadow root (everything else)], both safe to print inside <style>.
     *
     * @return array{0: string, 1: string}
     */
    public function css(Landing $landing): array
    {
        $css = (string) $landing->css;

        if (trim($css) === '') {
            return ['', ''];
        }

        // Nothing may end the <style> element early, or write markup after it.
        $css = str_replace('<', '\3C ', $css);
        $css = (string) preg_replace('/@import\b[^;]*;?/i', '', $css);
        $css = $this->assets($css, $landing);
        $css = (string) preg_replace('/:(?:scope|root)\b/', ':host', $css);

        $outside = '';
        while (($at = stripos($css, '@font-face')) !== false) {
            $end = $this->blockEnd($css, $at);
            $outside .= substr($css, $at, $end - $at)."\n";
            $css = substr($css, 0, $at).substr($css, $end);
        }

        return [trim($outside), trim($css)];
    }

    /** `assets/…` in an attribute, a srcset or a url() → the landing's folder on the public disk. */
    public function assets(string $text, Landing $landing): string
    {
        $base = rtrim(Storage::disk('public')->url($landing->filesDir()), '/').'/';

        return (string) preg_replace('~(^|["\'(\s,=])(?:\./)?assets/~', '$1'.$base, $text);
    }

    /** Where the block opened at $at closes (its matching brace), or the end of the text. */
    protected function blockEnd(string $css, int $at): int
    {
        $open = strpos($css, '{', $at);

        if ($open === false) {
            return strlen($css);
        }

        for ($i = $open, $depth = 0, $n = strlen($css); $i < $n; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth === 0) {
                return $i + 1;
            }
        }

        return strlen($css);
    }

    /**
     * Attaches the shadow roots a browser or htmx left as templates, and
     * takes "#…" links to an id inside a landing — a click on one, an
     * address changed to one, a page opened at one. Once per page: a copy
     * that comes again (in an htmx fragment) stops at once, and the first
     * one's listeners do the work.
     */
    protected function script(): string
    {
        if ($this->scriptPrinted) {
            return '';
        }

        $this->scriptPrinted = true;

        return '<script nonce="'.e(csp_nonce()).'">(function(){'
            .'if(window.torgashLandings)return;window.torgashLandings=1;'
            .'function a(r){(r||document).querySelectorAll("[data-landing]>template[shadowrootmode]").forEach(function(t){'
            .'var h=t.parentNode;if(h.shadowRoot)return;h.attachShadow({mode:"open"}).appendChild(t.content);t.remove();});}'
            .'function d(s){try{return decodeURIComponent(s)}catch(x){return s}}'
            .'function g(s,id){var t=id&&s.getElementById(id);if(!t)return false;'
            .'s.host.dispatchEvent(new CustomEvent("torgash:unfold",{bubbles:true}));'
            .'requestAnimationFrame(function(){t.scrollIntoView();});return true;}'
            .'function h(){var id=d(location.hash.slice(1));if(!id||document.getElementById(id))return;'
            .'[].some.call(document.querySelectorAll("[data-landing]"),function(x){return x.shadowRoot&&g(x.shadowRoot,id);});}'
            .'document.addEventListener("click",function(e){if(e.defaultPrevented||e.button||e.metaKey||e.ctrlKey||e.shiftKey||e.altKey)return;'
            .'var l=e.composedPath().find(function(n){return n.localName==="a"&&n.hasAttribute("href");}),s=l&&l.getRootNode(),f=l&&l.getAttribute("href");'
            .'if(s&&s.host&&s.host.hasAttribute("data-landing")&&f.charAt(0)==="#"&&g(s,d(f.slice(1)))){e.preventDefault();history.pushState(null,"",f);}});'
            .'addEventListener("hashchange",h);addEventListener("load",h);'
            .'a();document.addEventListener("DOMContentLoaded",function(){a();});'
            .'document.addEventListener("htmx:afterSwap",function(e){a(e.target);});})();</script>';
    }

    public function flush(): void
    {
        $this->scriptPrinted = false;
    }
}
