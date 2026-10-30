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

        // The numbers beyond the main one, each under what it is for.
        $points = [];
        foreach ($storefront->contactList('phones') as $row) {
            if ($row['number'] !== '' && $row['number'] !== $phone) {
                $points[] = [
                    $at.'type' => 'ContactPoint',
                    'telephone' => $row['number'],
                    'contactType' => $row['label'] !== '' ? $row['label'] : 'customer service',
                ];
            }
        }
        if ($points) {
            $organisation['contactPoint'] = $points;
        }

        // The legal address is one typed line, so it is the street part of a
        // PostalAddress with the shop's country beside it: the shape a search
        // engine reads, where a bare string is merely tolerated.
        if ($address = $storefront->contact('address')) {
            $organisation['address'] = array_filter([
                $at.'type' => 'PostalAddress',
                'streetAddress' => preg_replace('/\s+/u', ' ', $address),
                'addressCountry' => strtoupper(trim((string) setting('core', 'default_country'))),
            ]);
        }

        // The logo for a light background — what a search result sits on.
        if ($logo = theme_image('logo')) {
            $organisation['logo'] = url($logo);
        }

        // The shop's own pages elsewhere: how a search engine learns that this
        // shop and that account on a social network are one.
        $profiles = array_values(array_filter(
            array_column($storefront->contactList('socials'), 'url'),
            fn (string $url) => (bool) preg_match('#^https?://#i', $url),
        ));
        if ($profiles) {
            $organisation['sameAs'] = $profiles;
        }

        // Opening hours, where the typed line reads as days and times
        // (OpeningHours — "Пн–Пт 9:00–18:00", "Ежедневно 10:00–20:00"):
        // schema.org gives them to a place, so the node is then a
        // LocalBusiness as well as an Organization. A line that does not read
        // stays the page's own text and the node stays as it was.
        if ($hours = OpeningHours::parse($storefront->contact('hours'))) {
            $organisation[$at.'type'] = ['Organization', 'LocalBusiness'];
            $organisation['openingHoursSpecification'] = $hours;
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
