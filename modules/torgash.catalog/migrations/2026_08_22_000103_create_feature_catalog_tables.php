<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Global, managed feature catalog — distinct from the free-form JSONB
 * `features` column on products (see the earlier catalog migration). That
 * column stays for per-product, one-off specs (no filter/reuse value, and
 * cataloging every parsed spec as a global row just produces near-duplicate
 * clutter). This catalog is for the smaller set of characteristics that are
 * genuinely shared/filterable across products — e.g. Color, Brand, RAM —
 * modeled after the usual product-features catalogue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_catalog_feature_groups', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('m_catalog_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->nullable()->constrained('m_catalog_feature_groups')->nullOnDelete();
            $table->jsonb('name');
            // filter: shows as a catalog filter. variation: an axis products can
            // differ by (color/size). info: display-only spec, no filter UI.
            $table->string('purpose')->default('filter');
            // select reads its options from feature_values; text/number are typed
            // free entry per product (still catalogued, unlike JSONB features).
            // (No multiselect: product_features below is one row per product+feature.)
            $table->string('input_type')->default('select');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('m_catalog_feature_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('feature_id')->constrained('m_catalog_features')->cascadeOnDelete();
            $table->jsonb('value');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        // No rows for a feature_id = applies to all categories.
        Schema::create('m_catalog_feature_category', function (Blueprint $table) {
            $table->foreignId('feature_id')->constrained('m_catalog_features')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('m_catalog_categories')->cascadeOnDelete();
            $table->primary(['feature_id', 'category_id']);
        });

        Schema::create('m_catalog_product_features', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            $table->foreignId('feature_id')->constrained('m_catalog_features')->cascadeOnDelete();
            $table->foreignId('feature_value_id')->nullable()->constrained('m_catalog_feature_values')->nullOnDelete();
            $table->string('value_text')->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'feature_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_catalog_product_features');
        Schema::dropIfExists('m_catalog_feature_category');
        Schema::dropIfExists('m_catalog_feature_values');
        Schema::dropIfExists('m_catalog_features');
        Schema::dropIfExists('m_catalog_feature_groups');
    }
};
