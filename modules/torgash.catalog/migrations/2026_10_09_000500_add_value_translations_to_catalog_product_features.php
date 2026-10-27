<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A global characteristic's TYPED value in other languages.
 *
 * A picked value is a row of the value catalogue and is translated there; a
 * typed one ("24 months of warranty") lived in `value_text` in one language
 * only. `value_text` stays what it is — the BASE-language word every filter,
 * condition, measurement and exchange reads — and its words in another
 * language live beside it, `{"ru": "24 месяца"}`, the base word standing in
 * wherever a language has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_product_features', function (Blueprint $table) {
            $table->jsonb('value_translations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_product_features', function (Blueprint $table) {
            $table->dropColumn('value_translations');
        });
    }
};
