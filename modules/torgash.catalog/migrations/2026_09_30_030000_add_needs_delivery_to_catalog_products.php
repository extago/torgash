<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a product is sent anywhere (PLAN §40.10/2). Off for a service, a
 * download, a gift card: a basket holding only such products is placed with
 * no address and no delivery method. True for every product that exists,
 * which is what every product has meant until now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->boolean('needs_delivery')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn('needs_delivery');
        });
    }
};
