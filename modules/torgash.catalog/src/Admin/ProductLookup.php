<?php

namespace Torgash\Catalog\Admin;

use Torgash\Catalog\Models\Product;

/**
 * Products for an admin picker, by what the admin types — never the whole
 * catalogue printed into the page. A picker of products (related products, a
 * collection's, a landing's, a look's point) is core's <x-admin.picker> with a
 * `source`: the screen's own route answers `?q=` with search(), and the chips
 * already chosen get their words from options(). Each module serves it from
 * its OWN route, behind its own permission — a marketer who may edit
 * collections is not thereby allowed into the catalogue.
 */
class ProductLookup
{
    /** A screenful: enough to pick from, small enough to send on every keystroke. */
    public const LIMIT = 30;

    /** @return list<array{id: int, label: string}> */
    public function search(string $needle, array $except = []): array
    {
        $needle = trim($needle);

        if ($needle === '') {
            return [];
        }

        $like = '%'.addcslashes($needle, '%_\\').'%';

        return Product::query()
            ->whereNotIn('id', array_map('intval', $except))
            ->where(fn ($q) => $q
                ->where('sku', 'ilike', $like)
                // Every translation: an admin may know the name in one language only.
                ->orWhereRaw('exists (select 1 from jsonb_each_text(name) as t(k, v) where t.v ilike ?)', [$like])
                ->when(ctype_digit($needle), fn ($q) => $q->orWhere('id', (int) $needle)))
            // An exact SKU first, then the SKUs that start with what was typed.
            ->orderByRaw('lower(sku) = lower(?) desc, sku ilike ? desc', [$needle, addcslashes($needle, '%_\\').'%'])
            ->orderBy('sku')
            ->limit(self::LIMIT)
            ->get(['id', 'sku', 'name'])
            ->map(fn (Product $p) => $this->option($p))
            ->all();
    }

    /**
     * The chosen ones, with their words — in the order asked (related products
     * are ordered). A product since moved to the trash keeps its chip.
     *
     * @return list<array{id: int, label: string}>
     */
    public function options(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        if ($ids === []) {
            return [];
        }

        $found = Product::withTrashed()->whereIn('id', $ids)->get(['id', 'sku', 'name'])->keyBy('id');

        return collect($ids)->filter(fn ($id) => $found->has($id))
            ->map(fn ($id) => $this->option($found[$id]))->values()->all();
    }

    /** @return array{id: int, label: string} */
    public function option(Product $product): array
    {
        return ['id' => (int) $product->id, 'label' => $product->sku.' — '.$product->t('name', admin_content_locale())];
    }
}
