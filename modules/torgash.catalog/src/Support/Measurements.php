<?php

namespace Torgash\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;

/**
 * Where a product's weight comes from when the field is empty.
 *
 * The case this exists for is the ordinary one: a catalogue of thousands
 * arrives from a supplier with the weight sitting in a spec ("Вес", "0.65 кг")
 * and nothing in the weight field, so every shipping band would charge as if
 * the cart weighed nothing. The shop names that spec ONCE in the catalogue
 * settings and every weight question — bands, carrier requests, "can this be
 * priced at all" — is answered from it.
 *
 * The chain is: the column, then the named spec, then null. Null means nobody
 * has measured it, which is a real answer the shipping engine acts on rather
 * than a zero it would silently charge on.
 *
 * The named spec may be either kind of characteristic this engine has: a key in
 * the product's own free-form JSONB `features` map (nothing extra to load — it
 * is on the row), or the code of a global, managed Feature (one row per
 * product, so it is BATCHED — prime() takes the whole set at once, the same
 * shape as Torgash\Warehouses\StockAllocator::prime()).
 *
 * Request-scoped singleton: the settings lookup and the primed values must not
 * become a query per product, and a static would outlive the request under a
 * persistent worker.
 */
class Measurements
{
    /** @var array{source: string, key: string, unit: string, feature_id: ?int}|null|false */
    protected array|false|null $spec = false;

    /** @var array<int, string|null> product id => raw spec value, primed in one query */
    protected array $primed = [];

    /** Weight in grams, or null when nothing knows it. */
    public function weightOf(Product $product): ?int
    {
        if ($product->weight !== null) {
            return $product->weight;
        }

        $spec = $this->spec();

        if ($spec === null) {
            return null;
        }

        return $this->toGrams($this->rawValue($product, $spec), $spec['unit']);
    }

    /**
     * Announce the whole set before asking about it one by one — a cart or a
     * grid resolves N products in one query instead of N.
     *
     * @param  iterable<Product>  $products
     */
    public function prime(iterable $products): void
    {
        $spec = $this->spec();

        if ($spec === null || $spec['source'] !== 'feature') {
            return;
        }

        $ids = [];
        foreach ($products as $product) {
            // Products whose own column answers need nothing loaded for them.
            if ($product->weight === null && ! array_key_exists($product->id, $this->primed)) {
                $ids[] = $product->id;
            }
        }

        if ($ids === []) {
            return;
        }

        // A typed value sits in value_text; a picked one is a row in the value
        // catalogue, whose text is translatable — any language will do for a
        // number, so the base locale is read and the code is the last resort.
        $rows = DB::table('m_catalog_product_features as pf')
            ->leftJoin('m_catalog_feature_values as fv', 'fv.id', '=', 'pf.feature_value_id')
            ->where('pf.feature_id', $spec['feature_id'])
            ->whereIn('pf.product_id', $ids)
            ->selectRaw('pf.product_id, coalesce(pf.value_text, fv.value->>?, fv.code) as raw_value', [
                content_base_locale(),
            ])
            ->get()
            ->keyBy('product_id');

        // Every id asked for is recorded, including the ones with no row: a
        // second pass must not re-query for products that simply have no value.
        foreach ($ids as $id) {
            $this->primed[$id] = $rows[$id]->raw_value ?? null;
        }
    }

    /**
     * Which spec holds the weight, resolved once per request.
     *
     * A setting naming a global feature's code wins over a JSONB key of the
     * same name — the managed catalogue is the more specific answer, and a shop
     * that has both meant the one it curates.
     *
     * @return array{source: string, key: string, unit: string, feature_id: ?int}|null
     */
    protected function spec(): ?array
    {
        if ($this->spec !== false) {
            return $this->spec;
        }

        $key = trim((string) setting('torgash.catalog', 'weight_feature', ''));

        if ($key === '') {
            return $this->spec = null;
        }

        $unit = (string) setting('torgash.catalog', 'weight_feature_unit', 'kg');
        $featureId = Feature::whereRaw('lower(code) = ?', [mb_strtolower($key)])->value('id');

        return $this->spec = [
            'source' => $featureId ? 'feature' : 'json',
            'key' => $key,
            'unit' => $unit,
            'feature_id' => $featureId,
        ];
    }

    /** @param  array{source: string, key: string, unit: string, feature_id: ?int}  $spec */
    protected function rawValue(Product $product, array $spec): mixed
    {
        if ($spec['source'] === 'json') {
            return ($product->features ?? [])[$spec['key']] ?? null;
        }

        if (! array_key_exists($product->id, $this->primed)) {
            // Not primed: answer correctly rather than not at all, and let the
            // query budget test be the thing that complains about the caller.
            $this->prime([$product]);
        }

        return $this->primed[$product->id] ?? null;
    }

    /**
     * "0,65 кг" → 650. A spec written by a supplier carries its unit in the
     * text as often as not, so the number is taken and the unit comes from the
     * setting — guessing between "500" grams and "500" pounds from the string
     * would be worse than being told.
     */
    protected function toGrams(mixed $raw, string $unit): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! preg_match('/-?\d+(?:[.,]\d+)?/', (string) $raw, $match)) {
            return null;
        }

        $grams = units()->weightToStorage($match[0], $unit);

        return $grams !== null && $grams > 0 ? $grams : null;
    }
}
