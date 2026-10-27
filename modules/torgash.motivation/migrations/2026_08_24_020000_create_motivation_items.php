<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each row is one accordion card (icon + title + content) shown on the
 * product page. Scoping follows the usual motivation-block schema exactly — an
 * empty pivot means "show everywhere", a
 * non-empty one means "only these" or "everywhere except these" depending on
 * exclude_categories/exclude_products, rather than a three-state enum that
 * could disagree with an empty picker.
 *
 * Dropped versus the original because Torgash has no such entity to
 * scope against: shipping "destinations" (zone/country groups), geo-map
 * shipping estimation, product tags, and multivendor per-vendor editing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_motivation_items', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('expanded')->default(true);
            $table->boolean('enabled')->default(true);

            // none | image | lucide
            $table->string('icon_type')->default('none');
            $table->string('icon_name')->nullable();
            $table->string('icon_color')->nullable();

            // html | categories | payment_methods | shipping_methods
            $table->string('content_type')->default('html');
            $table->jsonb('content')->nullable();

            $table->boolean('exclude_categories')->default(false);
            $table->boolean('exclude_products')->default(false);

            $table->timestamps();
        });

        Schema::create('m_motivation_item_categories', function (Blueprint $table) {
            $table->foreignId('motivation_item_id')->constrained('m_motivation_items')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('m_catalog_categories')->cascadeOnDelete();
            $table->primary(['motivation_item_id', 'category_id']);
        });

        Schema::create('m_motivation_item_products', function (Blueprint $table) {
            $table->foreignId('motivation_item_id')->constrained('m_motivation_items')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            $table->primary(['motivation_item_id', 'product_id']);
        });

        Schema::create('m_motivation_item_customer_groups', function (Blueprint $table) {
            $table->foreignId('motivation_item_id')->constrained('m_motivation_items')->cascadeOnDelete();
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
            $table->primary(['motivation_item_id', 'customer_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_motivation_item_customer_groups');
        Schema::dropIfExists('m_motivation_item_products');
        Schema::dropIfExists('m_motivation_item_categories');
        Schema::dropIfExists('m_motivation_items');
    }
};
