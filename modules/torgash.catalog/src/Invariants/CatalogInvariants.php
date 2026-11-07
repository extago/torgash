<?php

namespace Torgash\Catalog\Invariants;

use App\Core\Seo\PageMeta;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Features\Spelling;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Seo\ProductFacts;
use Torgash\Catalog\Support\VariationCards;

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
                        'detail' => ['Price: :price', ['price' => admin_unit_price((int) $p->price)]],
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
                'check' => fn (int $limit) => $this->silentPages($limit),
            ],

            'catalog.products_have_a_photograph' => [
                'label' => 'Every product on sale has a photograph',
                'explain' => 'A product with no photograph is a grey square in every listing and is absent from image search entirely. Usually an import that brought the rows and not the files. Add one on the product, or check what the exchange was told to fetch.',
                'severity' => 'warning',
                // A size with no photos of its own shows its card's
                // (Product::primaryImageUrl()) — what is asked is whether the
                // PAGE has one, and a card with none is one finding.
                'check' => fn (int $limit) => $this->perCard(
                    Product::query()
                        ->where('status', Product::STATUS_ACTIVE)
                        // Links count as a photo only while the shop shows them.
                        ->when(setting('torgash.catalog', 'external_images', false), fn ($q) => $q->whereRaw("coalesce(image_url, '') = ''"))
                        ->whereDoesntHave('media', fn ($q) => $q->where('collection_name', 'gallery')),
                    fn (Product $p) => $p->primaryImageUrl() !== null,
                    $limit,
                ),
            ],

            'catalog.characteristics_in_one_place' => [
                'label' => 'No product page prints a characteristic twice',
                'explain' => 'The product has a value of one of the shop\'s characteristics, and one of its own rows is called the same, so the specifications show the same thing twice — and whoever edits it next cannot tell which one counts (filters, variations and placeholders read only the shop\'s one). If the own row is just a longer wording of the value ("16 GB DDR4, two slots" beside the filter\'s "16 GB"), untick Specifications on the characteristic so it only filters. Otherwise delete the own row.',
                'severity' => 'warning',
                'check' => fn (int $limit) => $this->characteristicsPrintedTwice($limit),
            ],

            'catalog.values_spelled_once' => [
                'label' => 'No characteristic has one value written two ways',
                'explain' => 'Two values of one characteristic differ only in letter case, dashes or spaces, so each holds only its own products: a customer who ticks one in the filter misses the rest, and each gets a filter page of its own. Open the characteristic and use "Merge values" — the products, filter pages and labels move to the value you keep, and imports find it by both spellings.',
                'severity' => 'warning',
                'check' => fn (int $limit) => $this->valuesWrittenAlike($limit),
            ],
        ];
    }

    /**
     * Characteristics whose values are written alike (Features\Spelling), one
     * finding per characteristic naming the groups. Grouped in PHP, by the same
     * Spelling::groups() the characteristic's page shows — a second copy of
     * that rule in SQL would be one more thing to keep in step.
     *
     * @return list<array<string, mixed>>
     */
    protected function valuesWrittenAlike(int $limit): array
    {
        $findings = [];

        foreach (Feature::query()->where('input_type', 'select')->with('values')->orderBy('id')->get() as $feature) {
            $groups = Spelling::groups($feature->values);

            if ($groups === []) {
                continue;
            }

            $findings[] = [
                'id' => (string) $feature->id,
                'title' => (string) ($feature->t('name', self::locale()) ?: $feature->code),
                'detail' => ['Written alike: :values', ['values' => implode('; ', array_map(
                    fn (array $group) => implode(' = ', array_map(fn (FeatureValue $value) => (string) $value->display(self::locale()), $group)),
                    $groups,
                ))]],
                'url' => route('admin.catalog.features.edit', $feature->id),
            ];

            if (count($findings) >= $limit) {
                break;
            }
        }

        return $findings;
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
     * Two things are not a collision, and both were reported as one (the
     * clothing scenario, 2026-11-07):
     *
     *  - the sizes of one card. They are one product to a search engine —
     *    their pages name the card's head as canonical and only the head is
     *    in the sitemap (Support\VariationCards) — so a card counts once;
     *  - a title an SEO template makes different. What is compared is what
     *    the page PRINTS: the same PageMeta chain the product page runs
     *    (name → own fields → `core.page.meta`, where `torgash.seo` answers),
     *    asked of the products whose stored words collide. Asking it of the
     *    whole catalogue would render a template per product per night; the
     *    stored words are the cheap sieve, and a template that makes two
     *    different names the SAME is a template mistake the SEO screen shows.
     *
     * @return list<array<string, mixed>>
     */
    protected function duplicateTitles(int $limit): array
    {
        $locale = self::locale();
        $title = "coalesce(nullif(meta_title->>?, ''), name->>?)";
        $key = VariationCards::keySql();

        // One row per card: its oldest active member, as the sitemap offers it.
        $cards = DB::table('m_catalog_products')
            ->selectRaw("distinct on ({$key}) id, {$title} as title", [$locale, $locale])
            ->whereNull('deleted_at')
            ->where('status', Product::STATUS_ACTIVE)
            ->whereRaw("coalesce({$title}, '') <> ''", [$locale, $locale])
            ->orderByRaw("{$key}, id");

        // More groups than will be reported: a template may part some of them.
        $groups = DB::query()->fromSub($cards, 'cards')
            ->selectRaw("lower(title) as title, string_agg(id::text, ',' order by id) as ids")
            ->groupByRaw('1')
            ->havingRaw('count(*) > 1')
            ->orderByRaw('count(*) desc')
            ->limit($limit * 5)
            ->get();

        $was = app()->getLocale();
        app()->setLocale($locale);
        $found = [];

        try {
            foreach ($groups as $group) {
                $products = Product::with('categories')
                    ->whereIn('id', array_map('intval', explode(',', (string) $group->ids)))
                    ->orderBy('id')
                    ->get();

                $byPrinted = $products->groupBy(fn (Product $p) => mb_strtolower($this->printedTitle($p)));

                foreach ($byPrinted as $printed => $same) {
                    if ($printed === '' || $same->count() < 2) {
                        continue;
                    }

                    $found[] = [
                        'id' => 'title:'.md5((string) $printed),
                        'title' => $this->printedTitle($same->first()),
                        'detail' => [':count products: :skus', ['count' => $same->count(), 'skus' => $same->pluck('sku')->filter()->implode(', ')]],
                        'url' => route('admin.catalog.products.edit', $same->first()->id),
                    ];

                    if (count($found) >= $limit) {
                        break 2;
                    }
                }
            }
        } finally {
            app()->setLocale($was);
        }

        return $found;
    }

    /**
     * Product pages with nothing of their own to say — and not saying it
     * through anybody else either (the clothing scenario, 2026-11-07):
     *
     *  - a size that takes its description from another product
     *    (`Product::contentFrom()`) has one — what its page prints;
     *  - a description an SEO template writes is one too;
     *  - the sizes of one card that are all silent are ONE finding: they are one page to a search engine
     *    (Support\VariationCards), and one description fixes them all —
     *    the newest of them names the card.
     *
     * The stored columns are the sieve (both empty), read newest first in
     * chunks; the rest is asked of each candidate until the limit is met.
     *
     * @return list<array<string, mixed>>
     */
    protected function silentPages(int $limit): array
    {
        $locale = self::locale();

        return $this->perCard(
            Product::query()
                ->where('status', Product::STATUS_ACTIVE)
                ->whereRaw("coalesce(description->>?, '') = ''", [$locale])
                ->whereRaw("coalesce(meta_description->>?, '') = ''", [$locale]),
            fn (Product $p) => $this->saysSomething($p),
            $limit,
        );
    }

    /**
     * The products a cheap SQL sieve let through that are still at fault once
     * asked properly (`$fine` says they are not), ONE per variation card —
     * the sizes of a card are one page, and one fix mends them all. Newest
     * first, in chunks, until the limit; in the shop's base language.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<Product>  $sieve
     * @param  callable(Product): bool  $fine
     * @return list<array<string, mixed>>
     */
    protected function perCard(\Illuminate\Database\Eloquent\Builder $sieve, callable $fine, int $limit): array
    {
        $was = app()->getLocale();
        app()->setLocale(self::locale());
        $found = [];
        $cards = [];

        try {
            $sieve->select('m_catalog_products.*')
                ->selectRaw(VariationCards::keySql().' as card_key')
                ->with(['media', 'contentSource.media', 'categories'])
                ->chunkByIdDesc(200, function ($products) use (&$found, &$cards, $fine, $limit) {
                    foreach ($products as $p) {
                        if (isset($cards[$p->card_key]) || $fine($p)) {
                            continue;
                        }

                        $cards[$p->card_key] = true;
                        $found[] = [
                            'id' => (string) $p->id,
                            'title' => $p->sku ?: $p->t('name'),
                            'detail' => (string) $p->t('name'),
                            'url' => route('admin.catalog.products.edit', $p->id),
                        ];

                        if (count($found) >= $limit) {
                            return false;
                        }
                    }
                }, 'm_catalog_products.id', 'id');
        } finally {
            app()->setLocale($was);
        }

        return $found;
    }

    /** A description read through its source, or one an SEO template writes. */
    protected function saysSomething(Product $product): bool
    {
        if (trim(strip_tags((string) $product->t('description'))) !== '') {
            return true;
        }

        return (new PageMeta)->describe($product)->description() !== '';
    }

    /** The product page's own title, worked out by the chain the page runs (StorefrontController::describeProduct). */
    protected function printedTitle(Product $product): string
    {
        return (new PageMeta)
            ->set('title', (new ProductFacts($product))->name())
            ->describe($product)
            ->rawTitle();
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
