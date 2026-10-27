<?php

namespace Torgash\Catalog\Features;

use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * Where a product's own rows and the shop's characteristics say the same thing.
 *
 * A product's characteristics live in two stores, and neither is a copy of the
 * other (catalog.md, "Two stores of characteristics"):
 *
 *  - the shop's characteristics — `m_catalog_features`, their values, and one
 *    row per product in `m_catalog_product_features`. The shop's vocabulary:
 *    filters, filter pages, variations, the brand, `{feature:…}`, labels'
 *    `[feature:…]`, the composed short description, delivery conditions, the
 *    weight characteristic;
 *  - the product's own rows — `m_catalog_products.features` (with
 *    `features_layout` and `features_translations`): words typed for this one
 *    product, printed under the shop's ones and read by nothing that filters.
 *
 * An own row may carry a characteristic's NAME on purpose: "RAM: 16 GB DDR4,
 * two slots" is the page's longer wording of the shop's RAM = 16 GB, which is
 * the bucket the filter needs. Nothing is wrong with that until the page prints
 * both — then the reader gets the same line twice, and whoever edits it
 * (the live shop's agent changed a colour in both places) cannot tell which one
 * counts. So the fault is exactly this: the product HAS a value of the shop's
 * characteristic, the characteristic is shown in the specifications, and an own
 * row is called like it. The way out is either to delete the own row, or to
 * untick "Specifications" on the characteristic so it only filters.
 *
 * "Called like" is the characteristic's name in any language, case and
 * surrounding spaces ignored (spaces only, as Postgres's btrim()) — not its
 * code: a code is a machine's word, and "Power" on a kettle is not the
 * charging power coded `power`.
 *
 * The invariant `catalog.characteristics_in_one_place` asks the same question
 * of the whole catalogue in SQL; the two must agree (CharacteristicStoresTest).
 */
class OwnRows
{
    /**
     * The own rows this product's page prints a second time — key => the
     * shop's characteristic it repeats. Reads the loaded assignments.
     *
     * @return array<string, Feature>
     */
    public function printedTwice(Product $product): array
    {
        $keys = array_map('strval', array_keys((array) ($product->features ?? [])));

        if ($keys === []) {
            return [];
        }

        // "Has a value" as the invariant's SQL says it: a picked value, or typed words.
        $printed = $product->featureAssignments
            ->filter(fn (ProductFeature $pf) => $pf->feature && $pf->feature->shows('specs')
                && ($pf->featureValue !== null || (string) $pf->value_text !== ''))
            ->map(fn (ProductFeature $pf) => $pf->feature);

        $twice = [];

        foreach ($keys as $key) {
            if ($feature = $printed->first(fn (Feature $f) => self::names($f, $key))) {
                $twice[$key] = $feature;
            }
        }

        return $twice;
    }

    /** Whether an own row's key is this characteristic's name in some language. */
    public static function names(Feature $feature, string $key): bool
    {
        $key = mb_strtolower(trim($key, ' '));

        if ($key === '') {
            return false;
        }

        foreach ((array) $feature->name as $name) {
            if (is_string($name) && mb_strtolower(trim($name, ' ')) === $key) {
                return true;
            }
        }

        return false;
    }
}
