<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A variation axis that stops being a choice once only one of its values is
 * still for sale: "New / As new" when the new ones are sold out. With
 * `hide_single_in_stock` the product page and the choice window leave that
 * row out (Variations::shown()) instead of offering a value that leads to an
 * item nobody can buy. The variants table keeps its column — it lists every
 * member, sold out or not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->boolean('hide_single_in_stock')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->dropColumn('hide_single_in_stock');
        });
    }
};
