<?php

namespace Torgash\Catalog\Invariants;

use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;

/**
 * What must be true about the catalogue, said by the module that owns it.
 *
 * Answered lazily through `guard.invariants`; nothing here depends on the
 * guard being installed.
 *
 * The first two are about a number that cannot mean what it says. A negative
 * price is the one an import writes and nobody sees until an order arrives with
 * a total to match; a negative stock is what a double-applied status leaves
 * behind.
 *
 * The three after them are about how the catalogue reads to a search engine,
 * and they are HERE rather than in `torgash.seo` for the reason every invariant
 * is where it is: the module that owns the data says what is true about it. A
 * product's title, its description and its photograph are the catalogue's, and
 * a shop with no SEO module installed still wants to be told that two of its
 * products are competing with each other in a search result.
 */
class CatalogInvariants
{
    /** @return array<string, array<string, mixed>> */
    public function register(): array
    {
        return [
            'catalog.prices_not_negative' => [
                'label' => 'No product has a negative price',
                'explain' => 'Almost always an import: a column read as a number that was not one, or a currency conversion against a rate that was wrong at the time. Open the product and set the price; if it came from an exchange, look at the mapping before the next run.',
                'check' => fn (int $limit) => Product::query()
                    ->where(fn ($q) => $q->where('price', '<', 0)
                        ->orWhere('list_price', '<', 0)
                        ->orWhere('cost_price', '<', 0))
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get()
                    ->map(fn (Product $p) => [
                        'id' => (string) $p->id,
                        'title' => $p->sku ?: $p->t('name'),
                        'detail' => ['Price: :price', ['price' => admin_price((int) $p->price)]],
                        'url' => route('admin.catalog.products.edit', $p->id),
                    ]),
            ],

            'catalog.stock_not_negative' => [
                'label' => 'No product is in stock by a negative number',
                'explain' => 'Stock went below zero, which means something was sold that was not there — an order whose stock was deducted twice, or a manual count that was already stale. Set the count on the product, then look at its recent orders.',
                'check' => fn (int $limit) => Product::query()
                    ->where('stock', '<', 0)
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get()
                    ->map(fn (Product $p) => [
                        'id' => (string) $p->id,
                        'title' => $p->sku ?: $p->t('name'),
                        'detail' => ['Stock: :qty', ['qty' => $p->stock]],
                        'url' => route('admin.catalog.products.edit', $p->id),
                    ]),
            ],

            'catalog.titles_are_distinct' => [
                'label' => 'No two products offer a search engine the same title',
                'explain' => 'Two pages with one title compete with each other, and the engine usually shows neither. Almost always variants of one thing ("T-shirt", "T-shirt") that need the size or the colour in the title — which is exactly what an SEO template with {feature:…} does. Give them different titles, or make them one product with variations.',
                'severity' => 'warning',
                'check' => fn (int $limit) => $this->duplicateTitles($limit),
            ],

            'catalog.pages_say_something' => [
                'label' => 'Every product page has something to say about the product',
                'explain' => 'Neither a description of its own nor a meta description, so the only sentence the page can offer a search result is the name and the price. Write a description, or write one SEO template for the category and fill them all in at once from "What is still empty".',
                'severity' => 'warning',
                'check' => fn (int $limit) => Product::query()
                    ->where('status', Product::STATUS_ACTIVE)
                    ->whereRaw("coalesce(description->>?, '') = ''", [self::locale()])
                    ->whereRaw("coalesce(meta_description->>?, '') = ''", [self::locale()])
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get()
                    ->map(fn (Product $p) => [
                        'id' => (string) $p->id,
                        'title' => $p->sku ?: $p->t('name'),
                        'detail' => (string) $p->t('name'),
                        'url' => route('admin.catalog.products.edit', $p->id),
                    ]),
            ],

            'catalog.products_have_a_photograph' => [
                'label' => 'Every product on sale has a photograph',
                'explain' => 'A product with no photograph is a grey square in every listing and is absent from image search entirely. Usually an import that brought the rows and not the files. Add one on the product, or check what the exchange was told to fetch.',
                'severity' => 'warning',
                'check' => fn (int $limit) => Product::query()
                    ->where('status', Product::STATUS_ACTIVE)
                    // Links count as a photo only while the shop shows them.
                    ->when(setting('torgash.catalog', 'external_images', false), fn ($q) => $q->whereRaw("coalesce(image_url, '') = ''"))
                    ->whereDoesntHave('media', fn ($q) => $q->where('collection_name', 'gallery'))
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get()
                    ->map(fn (Product $p) => [
                        'id' => (string) $p->id,
                        'title' => $p->sku ?: $p->t('name'),
                        'detail' => (string) $p->t('name'),
                        'url' => route('admin.catalog.products.edit', $p->id),
                    ]),
            ],

            'catalog.characteristics_in_one_place' => [
                'label' => 'No product page prints a characteristic twice',
                'explain' => 'The product has a value of one of the shop\'s characteristics, and one of its own rows is called the same, so the specifications show the same thing twice — and whoever edits it next cannot tell which one counts (filters, variations and placeholders read only the shop\'s one). If the own row is just a longer wording of the value ("16 GB DDR4, two slots" beside the filter\'s "16 GB"), untick Specifications on the characteristic so it only filters. Otherwise delete the own row.',
                'severity' => 'warning',
                'check' => fn (int $limit) => $this->characteristicsPrintedTwice($limit),
            ],
        ];
    }

    /**
     * Products whose page prints a characteristic twice — the question
     * Features\OwnRows::printedTwice() answers for one product, asked of the
     * whole catalogue: an own row called like (a name in any language, never
     * the code) a characteristic the product HAS a value of and the
     * specifications show. One finding per product, naming the rows.
     *
     * `features` is a map, but an emptied one was stored as `[]` for years,
     * and jsonb_object_keys() refuses an array outright. `shown_in` NULL is
     * the legacy "everywhere" (Feature::shows()).
     *
     * @return list<array<string, mixed>>
     */
    protected function characteristicsPrintedTwice(int $limit): array
    {
        $rows = DB::select(<<<'SQL'
            select p.id, p.sku, p.name->>? as name, string_agg(distinct k.key, ', ') as rows
            from m_catalog_products p
            cross join lateral jsonb_object_keys(case when jsonb_typeof(p.features) = 'object' then p.features else '{}'::jsonb end) as k(key)
            where p.deleted_at is null
              and exists (
                select 1
                from m_catalog_product_features pf
                join m_catalog_features f on f.id = pf.feature_id
                left join m_catalog_feature_values fv on fv.id = pf.feature_value_id
                where pf.product_id = p.id
                  and (f.shown_in is null or f.shown_in @> '["specs"]'::jsonb)
                  and (fv.id is not null or coalesce(pf.value_text, '') <> '')
                  and exists (select 1 from jsonb_each_text(f.name) n where lower(btrim(n.value)) = lower(btrim(k.key)))
              )
            group by p.id
            order by p.id desc
            limit ?
            SQL, [self::locale(), $limit]);

        return array_map(fn ($row) => [
            'id' => (string) $row->id,
            'title' => $row->sku ?: (string) $row->name,
            'detail' => ['Own rows: :rows', ['rows' => (string) $row->rows]],
            'url' => route('admin.catalog.products.edit', $row->id),
        ], $rows);
    }

    /**
     * Titles shared by more than one product, as ONE finding each: the fault is
     * the collision, not either of the products in it.
     *
     * Grouped in SQL because this is the one check that would otherwise have to
     * read the whole catalogue to answer, and every check here is bounded. The
     * title compared is the one the page would print — the product's own meta
     * title where it has one, its name otherwise — which is why it cannot be a
     * plain index on a column.
     *
     * @return list<array<string, mixed>>
     */
    protected function duplicateTitles(int $limit): array
    {
        $locale = self::locale();
        $title = "coalesce(nullif(meta_title->>?, ''), name->>?)";

        $rows = DB::table('m_catalog_products')
            ->selectRaw("{$title} as title, count(*) as total, min(id) as first_id, string_agg(sku, ', ' order by id) as skus", [$locale, $locale])
            ->whereNull('deleted_at')
            ->where('status', Product::STATUS_ACTIVE)
            ->whereRaw("coalesce({$title}, '') <> ''", [$locale, $locale])
            // By POSITION, not by repeating the expression: Postgres compares
            // the grouping expression textually, and the same expression
            // written twice carries different placeholder numbers — so it
            // refuses the query as ungrouped.
            ->groupByRaw('1')
            ->havingRaw('count(*) > 1')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($row) => [
            'id' => 'title:'.md5((string) $row->title),
            'title' => (string) $row->title,
            'detail' => [':count products: :skus', ['count' => $row->total, 'skus' => (string) $row->skus]],
            'url' => route('admin.catalog.products.edit', $row->first_id),
        ])->all();
    }

    /**
     * Which language these are judged in: the shop's base one.
     *
     * A catalogue that has not been translated has not been translated, which
     * is a different conversation from a duplicate — and a nightly run has no
     * visitor whose language it could borrow.
     */
    protected static function locale(): string
    {
        return content_base_locale();
    }
}
