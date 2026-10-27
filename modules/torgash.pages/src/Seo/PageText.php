<?php

namespace Torgash\Pages\Seo;

use App\Core\Content\Markdown;
use Torgash\Pages\Models\Page;

/**
 * A static page as text, at its own address plus `.md`.
 *
 * The same convention the catalogue uses, and for a better reason than
 * symmetry: what an agent is usually asked about a shop — how long delivery
 * takes, whether a return is possible, who to call — is written on these
 * pages and nowhere in the API. A catalogue readable by machine and terms of
 * sale that are not is half an answer.
 *
 * The body goes through `renderedBody()` first, so a placeholder is what a
 * reader sees rather than the token: `{phone}` in text form is a phone number,
 * not a curly-braced word the agent then repeats to a customer.
 */
class PageText
{
    public static function render(Page $page): string
    {
        $out = ['# '.$page->t('title'), ''];
        $out[] = '- '.__('Page address').': '.$page->url();
        $out[] = '';

        if ($body = Markdown::fromHtml($page->renderedBody())) {
            $out[] = $body;
            $out[] = '';
        }

        $children = $page->children()->published()->get()
            ->filter(fn (Page $child) => $child->isVisible());

        if ($children->isNotEmpty()) {
            $out[] = '## '.__('Pages in this section');
            $out[] = '';

            foreach ($children as $child) {
                $out[] = '- ['.$child->t('title').']('.$child->url().'.md)';
            }

            $out[] = '';
        }

        return trim(implode("\n", $out))."\n";
    }
}
