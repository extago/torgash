<?php

namespace App\Core\Seo;

use Illuminate\Support\Facades\Route;

/**
 * One `<script type="application/ld+json">` per page, holding a `@graph`.
 *
 * Three scripts on one page are three graphs, and a search engine does not
 * connect them: the Product does not know it belongs to the Organization, the
 * BreadcrumbList does not know which page it describes. One graph with `@id`s
 * says all of it, and costs the same bytes.
 *
 * So nothing prints JSON-LD of its own any more. Core seeds the graph with what
 * it alone knows — who the shop IS (`Organization`) and what the site is
 * (`WebSite`, with the search box a search engine may offer) — and whoever owns
 * the page adds its node: `Product` from the catalogue, `FAQPage` from a block,
 * `AggregateRating` from comments. The last word is the `core.structured_data`
 * filter.
 *
 * Nodes are keyed, so adding the same key twice replaces rather than duplicates
 * — a page rendered through two paths (a product and its variant) must not
 * offer two Products.
 *
 * An at-sign followed by a word is what this whole file is made of, which is
 * exactly what Blade's directive scanner misparses out of raw template text.
 * That is the second reason it lives in PHP: see the note in ProductSchema.
 */
class StructuredData
{
    /** @var array<string, array<string, mixed>> */
    protected array $nodes = [];

    /**
     * The `@id` of the shop itself, so another module's node can say "sold by
     * them" in one key instead of repeating the organization.
     */
    public function organisationId(): string
    {
        return rtrim(url('/'), '/').'/#organization';
    }

    /** @param  array<string, mixed>  $node */
    public function add(string $key, array $node): static
    {
        $this->nodes[$key] = $node;

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->nodes[$key]);
    }

    public function forget(string $key): static
    {
        unset($this->nodes[$key]);

        return $this;
    }

    /**
     * Everything on the page, in one list.
     *
     * @return list<array<string, mixed>>
     */
    public function graph(): array
    {
        $nodes = $this->nodes;

        if ($crumbs = app(Breadcrumbs::class)->schema()) {
            $nodes['breadcrumbs'] = $crumbs;
        }

        $nodes = array_merge($this->shopNodes(), $nodes);

        $nodes = hook_filter('core.structured_data', $nodes, ['page' => app(PageMeta::class)]);

        return array_values(array_filter((array) $nodes));
    }

    /** The whole script tag, or '' when there is nothing worth printing. */
    public function render(): string
    {
        $graph = $this->graph();

        if ($graph === []) {
            return '';
        }

        $at = '@';

        // JSON_HEX_TAG is not decoration: the block is not HTML-escaped (it
        // must stay valid JSON), so a literal `</script>` inside a product
        // description would end the tag and spill the rest onto the page.
        $json = json_encode(
            [$at.'context' => 'https://schema.org', $at.'graph' => $graph],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG,
        );

        return '<script type="application/ld+json">'.$json.'</script>';
    }

    /**
     * Who the shop is and what the site is — from the storefront row, because
     * that is where a shop's name, domain and contact details already live.
     *
     * Both carry a stable `@id` built from the site root, which is what lets a
     * module's node say "this product is sold by that organization" without
     * repeating the organization.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function shopNodes(): array
    {
        $storefront = current_storefront();

        if ($storefront === null) {
            return [];
        }

        $at = '@';
        $root = rtrim(url('/'), '/').'/';
        $organisationId = $this->organisationId();

        $organisation = [
            $at.'type' => 'Organization',
            $at.'id' => $organisationId,
            'name' => $storefront->name,
            'url' => $root,
        ];

        if ($phone = $storefront->contact('phone')) {
            $organisation['telephone'] = $phone;
        }

        if ($email = $storefront->contact('email')) {
            $organisation['email'] = $email;
        }

        $site = [
            $at.'type' => 'WebSite',
            $at.'id' => $root.'#website',
            'name' => $storefront->name,
            'url' => $root,
            'publisher' => [$at.'id' => $organisationId],
            'inLanguage' => app()->getLocale(),
        ];

        // Offered only when there is a search page to offer — the module that
        // owns it may be uninstalled, and a SearchAction pointing at a 404 is
        // worse than none.
        if (Route::has('catalog.search')) {
            $site['potentialAction'] = [
                $at.'type' => 'SearchAction',
                'target' => [
                    $at.'type' => 'EntryPoint',
                    'urlTemplate' => route('catalog.search').'?q={search_term_string}',
                ],
                'query-input' => 'required name=search_term_string',
            ];
        }

        return ['organization' => $organisation, 'website' => $site];
    }
}
