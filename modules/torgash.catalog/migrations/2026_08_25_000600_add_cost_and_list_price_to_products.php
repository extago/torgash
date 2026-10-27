<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cost_price (закупочная) feeds markup rules (see PriceTypeMarkupRule);
 * list_price (RRP/МРЦ) is display-only — product.blade.php shows it
 * crossed out with a discount badge when it exceeds the effective price,
 * reusing the markup that already exists for the group-discount case.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_price')->nullable()->after('price');
            $table->unsignedBigInteger('list_price')->nullable()->after('cost_price');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn(['cost_price', 'list_price']);
        });
    }
};
