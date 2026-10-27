<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment methods, and the snapshot columns an order keeps for how it ships and
 * pays.
 *
 * Shipping methods used to be created here too; they moved to the torgash.shipping
 * module (docs/PLAN.md §12.0). The order still records `shipping_method_name`
 * (and, in a later migration, `shipping_details`) as a frozen snapshot checkout
 * owns, so an order survives that module being uninstalled — the same rule the
 * taxes module follows with m_taxes_order_taxes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_checkout_payment_methods', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->jsonb('instructions')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('shipping_method_name')->nullable()->after('shipping_cost');
            $table->string('payment_method_name')->nullable()->after('shipping_method_name');
        });

        DB::table('m_checkout_payment_methods')->insert([
            ['name' => '{"en":"Card online","ru":"Банковская карта"}', 'instructions' => null, 'position' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => '{"en":"Cash on delivery","ru":"Наличными курьеру"}', 'instructions' => null, 'position' => 1, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => '{"en":"Bank transfer","ru":"Банковский перевод"}', 'instructions' => '{"en":"Transfer details will be sent to your email.","ru":"Реквизиты для перевода будут высланы на почту."}', 'position' => 2, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn(['shipping_method_name', 'payment_method_name']);
        });
        Schema::dropIfExists('m_checkout_payment_methods');
    }
};
