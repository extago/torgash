<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One card in the catalogue per colour, the sizes chosen on it (PLAN, the
 * clothing scenario; Support\VariationCards):
 *
 *  - `m_catalog_features.variation_role` — what a variation characteristic
 *    does to the catalogue: `card` (Colour — each value is a card of its own,
 *    which is what every group did before) or `choice` (Size — chosen on the
 *    card, never a card of its own);
 *  - `m_catalog_products.content_source_id` — "takes its content from": a
 *    size with no photos, description or characteristics of its own shows
 *    those of this product. Read when shown, never copied — every field of
 *    the size stays its own and editable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->string('variation_role', 16)->default('card');
        });

        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->foreignId('content_source_id')->nullable()->index()
                ->constrained('m_catalog_products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('content_source_id');
        });

        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->dropColumn('variation_role');
        });
    }
};
