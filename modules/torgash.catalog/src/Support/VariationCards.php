<?php

namespace Torgash\Catalog\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Stock\AvailabilityStatuses;

/**
 * One card in the catalogue per colour, the sizes chosen on it.
 *
 * A variation characteristic says what it does to the catalogue
 * (`Feature::variation_role`): `card` — every value its own card, which is
 * what every group did before the role existed — or `choice` — chosen on the
 * card. A product's CARD is its group plus its answers on the `card`
 * characteristics; the members sharing one are a single card, and a listing
 * shows ONE of them:
 *
 *  - the best of those that passed the listing's own conditions — so a
 *    filter "size 42" shows the shoe AND opens it at 42;
 *  - orderable first (the in-stock size stands in for a sold-out one), then
 *    the one others take their content from, then the oldest.
 *
 * The key is worked out in SQL each time, never stored: a renamed value, a
 * role switched in the admin, a product moved between groups — nothing has
 * to be recomputed, so nothing can be stale. A shop with no `choice`
 * characteristic gets its queries back untouched.
 */
class VariationCards
{
    /** Whether any characteristic is chosen on a card — without one, nothing collapses. */
    public function inUse(): bool
    {
        return Feature::query()
            ->where('purpose', Feature::PURPOSE_VARIATION)
            ->where('variation_role', Feature::ROLE_CHOICE)
            ->exists();
    }

    /**
     * The card a product row belongs to, as an SQL expression over `$table`.
     *
     * The `card` characteristics are named once for the whole statement
     * (`any(array(…))` is worked out one time) and not joined row by row,
     * which looked up every characteristic of every product: on a live shop
     * of 808 products that was half of a listing's query, 15.7 ms against 7.6.
     * Adding all the products' keys up ahead of the query came to the same
     * there (6.6) and was worse inside one category (3.1 against 2.1), because
     * it works the whole catalogue out to answer about thirty products.
     */
    public static function keySql(string $table = 'm_catalog_products'): string
    {
        return "case when coalesce({$table}.variation_group, '') = '' then 'p' || {$table}.id::text "
            ."else 'g' || {$table}.variation_group || '|' || coalesce(("
            ."select string_agg(pf.feature_id::text || ':' || coalesce(pf.feature_value_id::text, lower(pf.value_text)), ',' order by pf.feature_id, pf.feature_value_id) "
            .'from m_catalog_product_features pf '
            ."where pf.product_id = {$table}.id and pf.feature_id = any(array(select f.id from m_catalog_features f where f.purpose = 'variation' and f.variation_role = 'card'))"
            ."), '') end";
    }

    /**
     * The same query with one row per card. Its conditions decide which
     * members compete; its order is put back on the answer, so it may be
     * applied before or after the sort.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function collapse(Builder $query): Builder
    {
        if (! $this->inUse()) {
            return $query;
        }

        $inner = clone $query;
        $base = $inner->getQuery();
        $orders = $base->orders;
        $base->orders = null;

        if ($base->columns === null) {
            $inner->select('m_catalog_products.*');
        }

        [$orderable, $bindings] = app(AvailabilityStatuses::class)->orderableCase('m_catalog_products');
        $inner->selectRaw(
            'row_number() over (partition by '.self::keySql().' order by '.$orderable
            .' desc, (m_catalog_products.content_source_id is null) desc, m_catalog_products.id) as card_rank',
            $bindings,
        );

        $outer = $query->getModel()->newQuery()
            ->fromSub($inner, 'm_catalog_products')
            ->where('m_catalog_products.card_rank', 1)
            ->setEagerLoads($query->getEagerLoads());
        $outer->getQuery()->orders = $orders;

        return $outer;
    }

    /**
     * A list of product ids in ITS order (a search's, best match first) with
     * one id per card: the first of the card's members that can be bought,
     * else the first of them — kept where the card first appeared.
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    public function collapseIds(array $ids): array
    {
        if ($ids === [] || ! $this->inUse()) {
            return $ids;
        }

        [$orderable, $bindings] = app(AvailabilityStatuses::class)->orderableCase('m_catalog_products');
        $rows = DB::table('m_catalog_products')
            ->whereIn('id', $ids)
            ->selectRaw('id, '.self::keySql().' as card, '.$orderable.' as orderable', $bindings)
            ->get()->keyBy('id');

        $chosen = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            if (! $row) {
                continue;
            }

            $current = $chosen[$row->card] ?? null;
            if ($current === null || (! $current['orderable'] && $row->orderable)) {
                $chosen[$row->card] = ['id' => (int) $id, 'orderable' => (int) $row->orderable];
            }
        }

        // In the order the cards first appeared.
        $byFirst = [];
        foreach ($ids as $id) {
            $row = $rows->get($id);
            if ($row && ! isset($byFirst[$row->card])) {
                $byFirst[$row->card] = $chosen[$row->card]['id'];
            }
        }

        return array_values($byFirst);
    }

    /**
     * The products a search engine is offered: what a guest may see and is
     * not noindex, and of those ONE per card — the oldest. Sizes are chosen
     * on the card, nobody searches for size 44 on its own, so a dozen pages
     * that differ only by the size would be a dozen copies competing with
     * each other. The oldest and not "the one in stock": a canonical that
     * moved with the stock would tell the engine a different thing every day.
     *
     * @return Builder<Product>
     */
    public function indexable(): Builder
    {
        return Product::visible()->openToGuests()->where('noindex', false);
    }

    /**
     * The same query with only each card's head (see indexable()). Untouched
     * when no characteristic is chosen on a card.
     *
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function onlyHeads(Builder $query): Builder
    {
        if (! $this->inUse()) {
            return $query;
        }

        return $query->whereIn('m_catalog_products.id', $this->headIds());
    }

    /**
     * The card's head a member's page names as its canonical, or null when
     * the page is its own (it IS the head, it is alone on its card, or its
     * card has no head a search engine may be sent to).
     */
    public function canonicalFor(Product $product): ?Product
    {
        if (blank($product->variation_group) || ! $this->inUse()) {
            return null;
        }

        // The group first: a key begins with it, so nothing outside can match,
        // and the index finds a dozen rows instead of the key being worked
        // out for the whole catalogue (3.8 ms against 0.7, measured).
        $head = $this->indexable()
            ->where('variation_group', $product->variation_group)
            ->whereRaw(self::keySql().' = (select '.self::keySql('me').' from m_catalog_products me where me.id = ?)', [$product->id])
            ->orderBy('id')
            ->first();

        return $head && $head->id !== $product->id ? $head : null;
    }

    /** The heads' ids, as a subquery. */
    protected function headIds(): \Illuminate\Database\Query\Builder
    {
        $ranked = $this->indexable()->toBase()
            ->select('m_catalog_products.id')
            ->selectRaw('row_number() over (partition by '.self::keySql().' order by m_catalog_products.id) as head_rank');

        return DB::query()->fromSub($ranked, 'heads')->where('head_rank', 1)->select('heads.id');
    }
}
