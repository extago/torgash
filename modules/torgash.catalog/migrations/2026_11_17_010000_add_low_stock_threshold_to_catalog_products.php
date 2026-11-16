<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product's OWN "running low" line (PLAN §64.4): a fast seller is low at
 * five, a rare one at one. NULL — the shop's "Low stock threshold", as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_threshold')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn('low_stock_threshold');
        });
    }
};
