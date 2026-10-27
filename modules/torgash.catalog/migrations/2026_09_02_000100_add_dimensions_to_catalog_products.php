<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Length, width and height — in whole millimetres, like weight is in whole
 * grams (App\Core\Support\Units explains why storage is canonical).
 *
 * Four fixed engine fields rather than four features: volumetric weight is
 * computed from them by the shipping engine, an import has to map onto them,
 * and a carrier's payload names them — none of which can be done with a
 * free-text spec whose key each shop invents for itself.
 *
 * Weight becomes NULLABLE at the same time, and its zeros become nulls. "0 g"
 * and "nobody has measured this" are different answers, and the difference is
 * exactly what decides whether a method can price a cart at all or has to fall
 * back to "the carrier will quote it" (PLAN §12.4). Nothing in the catalogue
 * sells a weightless physical product, so the conversion loses no real value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->unsignedInteger('weight')->nullable()->default(null)->change();
            $table->unsignedInteger('length')->nullable()->after('weight');
            $table->unsignedInteger('width')->nullable()->after('length');
            $table->unsignedInteger('height')->nullable()->after('width');
        });

        DB::table('m_catalog_products')->where('weight', 0)->update(['weight' => null]);
    }

    public function down(): void
    {
        DB::table('m_catalog_products')->whereNull('weight')->update(['weight' => 0]);

        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn(['length', 'width', 'height']);
            $table->unsignedInteger('weight')->default(0)->nullable(false)->change();
        });
    }
};
