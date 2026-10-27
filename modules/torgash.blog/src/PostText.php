<?php

namespace Torgash\Blog;

use App\Core\Content\Markdown;
use Torgash\Blog\Models\Post;

/**
 * An article as text, at its address plus `.md` — the rule every page a
 * machine can read follows (core's `.md` renditions). Its products are named
 * with their addresses rather than drawn as cards.
 */
class PostText
{
    public static function render(Post $post): string
    {
        $out = ['# '.$post->t('title'), ''];
        $out[] = '- '.__('Published').': '.$post->published_at?->toDateString();
        if ($post->author_name) {
            $out[] = '- '.__('Author').': '.$post->author_name;
        }
        $rubrics = $post->rubrics()->where('enabled', true)->get()->map(fn ($r) => $r->t('name'))->filter()->implode(', ');
        if ($rubrics !== '') {
            $out[] = '- '.__('Rubrics').': '.$rubrics;
        }
        $out[] = '- '.__('Address').': '.$post->url();
        $out[] = '';

        if ($lead = trim(strip_tags((string) $post->t('lead')))) {
            $out[] = $lead;
            $out[] = '';
        }

        // The page's own tokens, as a page's .md gets them (a `{form:…}` then
        // drops out as `data-text-skip`); a product card has no text form.
        $body = expand_placeholders(preg_replace('~\{product:([^{}<>\s]+)\}~u', '', (string) $post->t('body')), ['post' => $post]);
        if ($text = Markdown::fromHtml($body)) {
            $out[] = $text;
            $out[] = '';
        }

        $products = $post->products()->listed()->get();
        if ($products->isNotEmpty()) {
            $out[] = '## '.__('Products in this article');
            $out[] = '';
            foreach ($products as $product) {
                $out[] = '- ['.$product->t('name').']('.route('catalog.product', $product->slug).')';
            }
            $out[] = '';
        }

        return implode("\n", $out);
    }
}
