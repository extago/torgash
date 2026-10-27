<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order placed for a COMPANY (setting `business_orders`): its name and its
 * tax code. Columns rather than a bag, because an invoice, an export and 1C
 * each read them by name — and NULL company_name is what "a private person"
 * means, so there is no separate buyer-type column to disagree with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('company_name', 190)->nullable()->after('customer_name');
            $table->string('company_tax_id', 40)->nullable()->after('company_name');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn(['company_name', 'company_tax_id']);
        });
    }
};
