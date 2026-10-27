<?php

namespace Torgash\Blog;

use Illuminate\Support\Str;
use Torgash\Blog\Models\Post;
use Torgash\Catalog\Models\Product;

/**
 * An article's text made ready for its page (PLAN §47), once per request:
 *
 * - every h2 and h3 gets an `id` (kept when the author gave one), and the
 *   list of them is the CONTENTS the page shows beside the text — nobody
 *   types a contents list, and one typed goes stale the first time a heading
 *   is renamed;
 * - `{product:SKU}` alone in its paragraph becomes that product's card with
 *   "open in the catalogue" — the owner's mockup: an article about phones
 *   whose every phone can be opened. A SKU nobody has, or a product the
 *   visitor may not see, leaves nothing behind rather than a broken card;
 * - core's placeholders are expanded, as in a page's text — `{form:code}` puts
 *   one of the shop's forms between two paragraphs (PLAN §48).
 *
 * Reads the text as the editor writes it (paragraphs and headings); it is not
 * an HTML parser and does not need to be one.
 */
class Article
{
    /**
     * @return array{html: string, contents: list<array{id: string, text: string, level: int}>}
     */
    public function prepare(Post $post): array
    {
        // Cleaned before the product cards and placeholders go in — those are
        // the engine's own markup (App\Core\Content\SafeHtml).
        $html = app(\App\Core\Content\SafeHtml::class)->clean((string) $post->t('body'));
        $contents = [];
        $taken = [];

        $html = preg_replace_callback('~<h([23])([^>]*)>(.*?)</h\1>~is', function (array $m) use (&$contents, &$taken) {
            $text = trim(html_entity_decode(strip_tags($m[3])));

            if ($text === '') {
                return $m[0];
            }

            $attributes = $m[2];
            if (preg_match('~\sid\s*=\s*"([^"]+)"~i', $attributes, $own)) {
                $id = $own[1];
            } else {
                $base = Str::slug($text) ?: 'section';
                $id = $base;
                for ($n = 2; isset($taken[$id]); $n++) {
                    $id = $base.'-'.$n;
                }
                $attributes .= ' id="'.e($id).'"';
            }

            $taken[$id] = true;
            $contents[] = ['id' => $id, 'text' => $text, 'level' => (int) $m[1]];

            // Marked for the contents' "you are here" (the page's own script).
            return '<h'.$m[1].$attributes.' data-blog-heading>'.$m[3].'</h'.$m[1].'>';
        }, $html) ?? $html;

        // Then the shop's own tokens, as a page's text gets them — `{form:…}`,
        // `{phone}`, `{icon_*}` (core's Placeholders). After the products, so
        // `{product:SKU}` stays this module's card and never meets a token
        // somebody else registers under the same name.
        return ['html' => expand_placeholders($this->products($html), ['post' => $post]), 'contents' => $contents];
    }

    /** `{product:SKU}` alone in a paragraph (or on its own) becomes the product's card. */
    protected function products(string $html): string
    {
        $pattern = '~(?:<p>\s*)?\{product:([^{}<>\s]+)\}(?:\s*</p>)?~u';

        if (! preg_match_all($pattern, $html, $all)) {
            return $html;
        }

        $products = Product::query()->listed()->withCardData()
            ->whereIn('sku', array_unique($all[1]))->get()->keyBy('sku');

        return preg_replace_callback($pattern, function (array $m) use ($products) {
            $product = $products->get($m[1]);

            return $product ? view('torgash.blog::partials.inline-product', ['inlineProduct' => $product])->render() : '';
        }, $html) ?? $html;
    }
}
