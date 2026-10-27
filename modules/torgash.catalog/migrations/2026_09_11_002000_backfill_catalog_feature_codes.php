<?php

use Illuminate\Database\Migrations\Migration;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Support\Codes;

/**
 * Codes for the characteristics and values that predate them.
 *
 * The admin has filled these in on save for a while (`Codes::fromName`), so a
 * catalogue edited since then already has them — but rows that came from a
 * seeder or an early import do not, and without a code a characteristic cannot
 * be spelled out in an address at all. Readable filters would then be a feature
 * that quietly does nothing until somebody re-saves every characteristic one by
 * one.
 *
 * Derived, not invented: exactly what the form would have written, through the
 * same class, with the same collision suffixes. Nothing already filled is
 * touched.
 *
 * `down()` does nothing on purpose — "this code was null in August" is not
 * recorded anywhere, and guessing it back would delete codes a shop has since
 * put in an address.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Feature::whereNull('code')->get() as $feature) {
            $name = $feature->name['en'] ?? $feature->name['ru'] ?? reset($feature->name) ?: null;

            $feature->timestamps = false;
            $feature->code = Codes::unique(
                $name ? Codes::fromName((string) $name) : 'FEATURE-'.$feature->id,
                'm_catalog_features',
                $feature->id,
            );
            $feature->save();
        }

        foreach (FeatureValue::whereNull('code')->with('feature')->get() as $value) {
            $name = $value->display('en') ?: $value->display();

            $value->timestamps = false;
            $value->code = Codes::unique(
                $name ? Codes::fromName((string) $name) : 'VALUE-'.$value->id,
                'm_catalog_feature_values',
                $value->id,
                ['feature_id' => $value->feature_id],
            );
            $value->save();
        }
    }

    public function down(): void
    {
        // See the class docblock.
    }
};
