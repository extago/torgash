<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A collection: its page (address, words, cover, dates, head) and its
 * `conditions` — the catalogue filter's own vocabulary, saved:
 *   {categories: [id…], features: {feature_id: [value_id…]}, flags: [key…],
 *    price_min: minor|null, price_max: minor|null}
 * plus products picked by hand and products left out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_collections', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            $table->string('cover')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('listed')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->jsonb('conditions')->nullable();
            $table->jsonb('meta_title')->nullable();
            $table->jsonb('meta_description')->nullable();
            $table->timestamps();
        });

        Schema::create('m_collection_products', function (Blueprint $table) {
            $table->foreignId('collection_id')->constrained('m_collections')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();
            $table->boolean('excluded')->default(false);
            $table->primary(['collection_id', 'product_id']);
            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_collection_products');
        Schema::dropIfExists('m_collections');
    }
};
