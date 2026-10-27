<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Named price types (retail/wholesale/CRM feed price, ...), modeled on
 * Bitrix's b_catalog_group × b_catalog_group_access but computed on read
 * (see Product::priceForType()) rather than a stored per-product grid like
 * Bitrix's b_catalog_price — a flat adjustment_percent plus the existing
 * ProductPriceTier/override mechanisms scale far better past ~2k SKUs than
 * one row per product per type. is_base's price IS Product::effectivePrice(),
 * unchanged — this table only ever adds *additional* named types on top.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_catalog_price_types', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->jsonb('name');
            $table->boolean('is_base')->default(false);
            // Ignored for the base type; e.g. -20 = 20% off Product::price.
            $table->integer('adjustment_percent')->nullable();
            $table->boolean('requires_auth')->default(false);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        // No rows for a price_type_id = visible to every customer group
        // (same "empty = applies to all" convention as m_catalog_feature_category).
        Schema::create('m_catalog_price_type_customer_group', function (Blueprint $table) {
            $table->foreignId('price_type_id')->constrained('m_catalog_price_types')->cascadeOnDelete();
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
            $table->primary(['price_type_id', 'customer_group_id']);
        });

        DB::table('m_catalog_price_types')->insert([
            'code' => 'base',
            'name' => json_encode(['en' => 'Retail', 'ru' => 'Розница']),
            'is_base' => true,
            'adjustment_percent' => null,
            'requires_auth' => false,
            'sort' => 0,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('m_catalog_price_type_customer_group');
        Schema::dropIfExists('m_catalog_price_types');
    }
};
