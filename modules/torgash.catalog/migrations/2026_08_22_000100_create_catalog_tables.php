<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('m_catalog_categories')->cascadeOnDelete();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->string('slug')->unique();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('m_catalog_products', function (Blueprint $table) {
            $table->id();
            $table->string('sku')->unique();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->string('slug')->unique();
            // Integer minor units of the base currency; never floats.
            $table->unsignedBigInteger('price');
            $table->integer('stock')->default(0);
            // Arbitrary filterable characteristics, e.g. {"color": "black", "memory_gb": 256}
            $table->jsonb('features')->default('{}');
            $table->string('image_url')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        DB::statement('CREATE INDEX m_catalog_products_features_gin ON m_catalog_products USING GIN (features)');

        Schema::create('m_catalog_category_product', function (Blueprint $table) {
            $table->foreignId('category_id')->constrained('m_catalog_categories')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            $table->primary(['category_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_catalog_category_product');
        Schema::dropIfExists('m_catalog_products');
        Schema::dropIfExists('m_catalog_categories');
    }
};
