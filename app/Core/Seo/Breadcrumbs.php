<?php

namespace App\Core\Seo;

/**
 * Where the visitor is, as DATA rather than as markup.
 *
 * They were assembled by hand in each template — a `<ul class="breadcrumbs">`
 * in the product page, another in the brand page, a third shape in the pages
 * module — and the cost of that is not the duplication. It is that a list of
 * `<li>`s cannot be turned into a `BreadcrumbList` for a search engine, so the
 * one place the trail is genuinely worth something was the one place it did not
 * exist. Now a controller states the trail, the theme prints it
 * (`<x-breadcrumbs>`) and {@see StructuredData} reads the same rows.
 *
 * The last crumb is the page itself and carries no URL: a link to the page you
 * are on is noise to a reader and a self-reference in the graph.
 *
 * Per request (a scoped binding, not a static) — a trail left in a static
 * outlives the request that built it.
 */
class Breadcrumbs
{
    /** @var list<array{label: string, url: ?string}> */
    protected array $items = [];

    protected bool $home = true;

    /** A controller states the trail from the home page onwards; the first crumb is added here. */
    public function add(string $label, ?string $url = null): static
    {
        $label = trim($label);

        if ($label !== '') {
            $this->items[] = ['label' => $label, 'url' => $url];
        }

        return $this;
    }

    /** @param  iterable<array{0: string, 1?: ?string}|array{label: string, url?: ?string}>  $items */
    public function push(iterable $items): static
    {
        foreach ($items as $item) {
            $this->add($item['label'] ?? $item[0], $item['url'] ?? $item[1] ?? null);
        }

        return $this;
    }

    /** The home page is not one of the crumbs the page states — it is where every trail starts. */
    public function withoutHome(): static
    {
        $this->home = false;

        return $this;
    }

    /**
     * The whole trail, home crumb included.
     *
     * Included HERE rather than drawn by the theme, and that is the point: the
     * printed trail and the `BreadcrumbList` in the structured data are then
     * the same list, which is not true of any arrangement where one side adds a
     * step the other cannot see.
     *
     * @return list<array{label: string, url: ?string}>
     */
    public function items(): array
    {
        $items = $this->items === [] || ! $this->home
            ? $this->items
            : [['label' => __('Home'), 'url' => rtrim(url('/'), '/').'/'], ...$this->items];

        return hook_filter('core.breadcrumbs', $items, []);
    }

    public function isEmpty(): bool
    {
        return $this->items() === [];
    }

    /**
     * The trail as schema.org sees it, or null when there is nothing to say.
     *
     * One crumb is not a trail: a `BreadcrumbList` of the page itself tells a
     * search engine nothing it did not have from the URL.
     *
     * @return array<string, mixed>|null
     */
    public function schema(): ?array
    {
        $items = $this->items();

        if (count($items) < 2) {
            return null;
        }

        $at = '@';
        $elements = [];

        foreach (array_values($items) as $position => $item) {
            $element = [
                $at.'type' => 'ListItem',
                'position' => $position + 1,
                'name' => $item['label'],
            ];

            if ($item['url']) {
                $element['item'] = $item['url'];
            }

            $elements[] = $element;
        }

        return [
            $at.'type' => 'BreadcrumbList',
            'itemListElement' => $elements,
        ];
    }
}
