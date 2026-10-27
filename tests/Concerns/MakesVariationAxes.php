<?php

namespace Tests\Concerns;

use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductFeature;

/**
 * A variation axis for a test: a GLOBAL characteristic whose purpose is
 * "variation", with listed values — what Support\Variations switches by.
 */
trait MakesVariationAxes
{
    /**
     * @param  list<string>  $values
     * @return array{0: Feature, 1: array<string, FeatureValue>}
     */
    protected function variationAxis(string $name, array $values, string $purpose = Feature::PURPOSE_VARIATION): array
    {
        $feature = Feature::create(['name' => ['en' => $name], 'purpose' => $purpose, 'input_type' => 'select', 'sort' => 0]);
        $made = [];

        foreach (array_values($values) as $i => $value) {
            $made[$value] = FeatureValue::create(['feature_id' => $feature->id, 'value' => ['en' => $value], 'sort' => $i]);
        }

        return [$feature, $made];
    }

    protected function answer(Product $product, FeatureValue $value): void
    {
        ProductFeature::create(['product_id' => $product->id, 'feature_id' => $value->feature_id, 'feature_value_id' => $value->id]);
    }
}
