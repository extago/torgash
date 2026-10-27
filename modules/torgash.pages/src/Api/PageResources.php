<?php

namespace Torgash\Pages\Api;

use App\Core\Display\DisplayRules;
use Torgash\Api\ApiResources;
use Torgash\Pages\Models\Page;

/**
 * Static pages over the API.
 *
 * `lookup` is the slug, because that is the key another system already knows a
 * page by — its address. The writable set is deliberately narrow: publishing,
 * ordering and sitemap membership are single facts, while the texts are edited
 * on a screen that has a language switcher and a rich editor beside it, and a
 * PATCH that could half-write one locale of a jsonb column is a way to lose
 * the other one.
 */
class PageResources
{
    public function register(): void
    {
        ApiResources::register('admin.pages', [
            'label' => 'Pages',
            'description' => 'Static pages — About us, Delivery, Privacy policy. Read in full, including the rendered body, so a headless storefront can print them; published, ordered and kept out of the sitemap through PATCH.',
            'permission' => 'pages.manage',
            'query' => fn () => Page::query(),
            'lookup' => ['slug'],
            'sortable' => ['id', 'position', 'slug', 'created_at', 'updated_at'],
            'filters' => [
                'enabled' => [
                    'description' => 'The switch only. A published page can still be absent from a storefront — see `shown_when`.',
                    'apply' => fn ($q, $v) => $q->where('enabled', filter_var($v, FILTER_VALIDATE_BOOLEAN)),
                ],
                'in_sitemap' => ['apply' => fn ($q, $v) => $q->where('in_sitemap', filter_var($v, FILTER_VALIDATE_BOOLEAN))],
                'updated_after' => [
                    'description' => 'Everything touched since a moment (ISO 8601).',
                    'apply' => fn ($q, $v) => $q->where('updated_at', '>=', $v),
                ],
            ],
            'serialize' => fn (Page $page) => $this->page($page),
            'writable' => [
                'enabled' => ['type' => 'boolean', 'description' => 'Published or a draft.'],
                'in_sitemap' => ['type' => 'boolean', 'description' => 'A page can be published and still not belong in the sitemap — a thank-you page, a campaign landing.'],
                'position' => ['type' => 'integer', 'description' => 'Order in the admin list. Navigation order is the menu’s, not this.'],
            ],
            'schema' => $this->schema(),
            // Narrow on purpose — see the class docblock. Anything else in the
            // body is ignored rather than half-applied.
            'update' => function (Page $page, array $data) {
                $page->fill(array_intersect_key($data, array_flip(['enabled', 'in_sitemap', 'position'])));
                $page->save();
            },
        ]);
    }

    /** @return array<string, mixed> */
    protected function schema(): array
    {
        return [
            'id' => 'integer',
            'slug' => ['type' => 'string', 'description' => 'The address: /page/{slug}. Renaming it in the admin leaves a 301 behind.'],
            'url' => 'string',
            'title' => ['type' => 'object', 'description' => 'Translatable: {"en": …, "ru": …}.'],
            'body' => ['type' => 'object', 'description' => 'Translatable HTML, as typed — placeholders NOT expanded.'],
            'rendered_body' => ['type' => 'string', 'description' => 'The body in the caller’s language with placeholders expanded, which is what the storefront prints.'],
            'page_title' => 'object',
            'meta_description' => 'object',
            'enabled' => 'boolean',
            'in_sitemap' => 'boolean',
            'position' => 'integer',
            'shown_when' => [
                'type' => 'array',
                'description' => 'Where the page is shown — storefront, language, who is looking — in words, one string per condition. Empty means everywhere.',
                'of' => ['type' => 'string'],
            ],
            'created_at' => ['type' => 'string|null', 'format' => 'date-time'],
            'updated_at' => ['type' => 'string|null', 'format' => 'date-time'],
        ];
    }

    /** @return array<string, mixed> */
    protected function page(Page $page): array
    {
        return [
            'id' => $page->id,
            'slug' => $page->slug,
            'url' => route('pages.show', $page->slug),
            'title' => $page->title,
            'body' => $page->body,
            'rendered_body' => $page->renderedBody(),
            'page_title' => $page->page_title,
            'meta_description' => $page->meta_description,
            'enabled' => (bool) $page->enabled,
            'in_sitemap' => (bool) $page->in_sitemap,
            'position' => (int) $page->position,
            'shown_when' => array_map(
                fn (array $rule) => app(DisplayRules::class)->describe($rule),
                (array) ($page->rules ?? []),
            ),
            'created_at' => $page->created_at?->toIso8601String(),
            'updated_at' => $page->updated_at?->toIso8601String(),
        ];
    }
}
