<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a characteristic MEANS, for a customer who does not know ("Nits",
 * "IP68"): opened from an "i" beside its name on the product page.
 * Translatable jsonb like the name; NULL = nothing to explain, no icon.
 * Where it is offered is the characteristic's own: on the product page, in
 * the catalogue's filter, or both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->jsonb('description')->nullable();
            $table->boolean('explain_on_product')->default(true);
            $table->boolean('explain_in_filter')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->dropColumn(['description', 'explain_on_product', 'explain_in_filter']);
        });
    }
};
