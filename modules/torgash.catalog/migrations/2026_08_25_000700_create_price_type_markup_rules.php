<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rules a price type uses to compute its price from Product::cost_price —
 * tried in `sort` order, first full match wins (every present condition
 * must match; an empty condition matches everything, same convention as
 * m_catalog_feature_category). A price type with zero rows here keeps
 * behaving exactly as before this migration (adjustment_percent off
 * Product::price, or the raw price) — see Product::priceForType().
 *
 * Conditions are flat structured columns/pivots, not a rule-tree DSL — same
 * precedent as torgash.checkout's Coupon (its migration explicitly
 * documents rejecting a serialized condition-tree engine).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_catalog_price_type_markup_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_type_id')->constrained('m_catalog_price_types')->cascadeOnDelete();
            $table->jsonb('name')->nullable();
            $table->unsignedBigInteger('cost_price_min')->nullable();
            $table->unsignedBigInteger('cost_price_max')->nullable();
            $table->string('markup_type', 8); // percent | fixed
            $table->integer('markup_value'); // percent: basis points (3500 = 35.00%); fixed: minor units
            $table->string('rounding_mode', 16); // nearest | psychological
            $table->unsignedInteger('round_to');
            $table->string('round_direction', 8)->nullable(); // nearest | up | down — 'nearest' mode only
            $table->integer('psychological_offset')->nullable(); // e.g. 1 for x999 — 'psychological' mode only
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // Multi-select conditions as real pivots (not jsonb id arrays) so a
        // deleted category/value doesn't leave a dangling id, and so
        // <x-admin.picker> can drive the UI the same way it already does
        // for product categories/labels.
        Schema::create('m_catalog_price_type_markup_rule_category', function (Blueprint $table) {
            $table->foreignId('rule_id')->constrained('m_catalog_price_type_markup_rules')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('m_catalog_categories')->cascadeOnDelete();
            $table->primary(['rule_id', 'category_id']);
        });

        Schema::create('m_catalog_price_type_markup_rule_feature_value', function (Blueprint $table) {
            $table->foreignId('rule_id')->constrained('m_catalog_price_type_markup_rules')->cascadeOnDelete();
            $table->foreignId('feature_value_id')->constrained('m_catalog_feature_values')->cascadeOnDelete();
            $table->primary(['rule_id', 'feature_value_id']);
        });

        // Club-price storefront display (see product.blade.php) — piggybacks
        // on this migration rather than a separate one-column migration.
        Schema::table('m_catalog_price_types', function (Blueprint $table) {
            $table->boolean('show_on_storefront')->default(false)->after('requires_auth');
            $table->jsonb('storefront_note')->nullable()->after('show_on_storefront');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_price_types', function (Blueprint $table) {
            $table->dropColumn(['show_on_storefront', 'storefront_note']);
        });
        Schema::dropIfExists('m_catalog_price_type_markup_rule_feature_value');
        Schema::dropIfExists('m_catalog_price_type_markup_rule_category');
        Schema::dropIfExists('m_catalog_price_type_markup_rules');
    }
};
