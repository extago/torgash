<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weight, in grams.
 *
 * Added because shipping rates can be priced by it, and an axis with no data
 * behind it is worse than no axis: an admin would build "0–5 kg costs 3.00",
 * every cart would weigh nothing, and every parcel would quietly fall in the
 * first band. Stored as whole grams for the same reason money is stored in
 * minor units — the form takes kilograms and converts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->unsignedInteger('weight')->default(0)->after('stock');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
