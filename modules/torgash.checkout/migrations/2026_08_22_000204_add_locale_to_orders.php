<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every order stores its language
 * so status-change emails go out in the CUSTOMER's language regardless of
 * which locale the admin happens to be browsing in when they change the
 * status. Same reasoning here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('locale', 5)->nullable()->after('currency_code');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('locale');
        });
    }
};
