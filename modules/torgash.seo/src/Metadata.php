<?php

namespace Torgash\Seo;

use App\Core\Seo\PageMeta;
use Torgash\Seo\Models\Template;

/**
 * The one place a template reaches a page.
 *
 * It listens on `core.page.meta` — the filter core asks at PRINT time — at an
 * early priority, because that same filter is the last word for whoever wants
 * one (a geo page, a filter page; PLAN §30.8–9). The chain in
 * App\Core\Seo\PageMeta reads: controller, the record's own columns, this, then
 * anybody else.
 *
 * `fill_empty` is measured against the RECORD, not against the values already
 * in PageMeta. By the time this runs the controller has put the product's name
 * in the title, so a template asking "is it empty?" of the merged values would
 * never fire once — which is the bug that makes this worth a paragraph.
 */
class Metadata
{
    public function __construct(protected Templates $templates) {}

    public function register(): void
    {
        hook_listen('core.page.meta', $this->apply(...), 5);
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function apply(array $values, array $context): array
    {
        $meta = $context['meta'] ?? null;
        $subject = $meta instanceof PageMeta ? $meta->subject() : null;

        if ($subject === null) {
            return $values;
        }

        $template = $this->templates->for($subject);

        if ($template === null || $template->mode === Template::MODE_SKIP) {
            return $values;
        }

        $own = $subject->pageMeta();
        $rendered = $this->templates->render($template, $subject);

        foreach ($rendered as $field => $text) {
            if ($text === '') {
                continue;
            }

            if ($template->mode === Template::MODE_FILL_EMPTY && ($own[$field] ?? null)) {
                continue;
            }

            $values[$field] = $text;
        }

        return $values;
    }
}
