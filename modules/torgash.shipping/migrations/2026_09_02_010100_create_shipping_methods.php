<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A way to deliver.
 *
 * It carries no price of its own: what it costs, and whether it is offered at
 * all, is a property of the pair (method, zone) — see m_shipping_rates — unless
 * `provider` names a carrier, and then that carrier prices it live from
 * `provider_config` (its variant: to branch, to locker, by courier). A method
 * whose provider is not installed is simply not offered.
 *
 * (Was torgash.checkout's m_checkout_shipping_methods — created there together
 * with payment methods, then grown a `provider` and `provider_config` column by
 * two later migrations. Consolidated into its final shape here when shipping
 * became its own module — docs/PLAN.md §12.0.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_shipping_methods', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');

            // Null: priced by rate bands in the winning zone. A value
            // ("nova_poshta"): the key of a ShippingProvider a module
            // registered; the zone gate still applies but the cost, the
            // variants and the extra checkout fields come from that provider.
            $table->string('provider', 64)->nullable();

            // Per-method settings a carrier reads — service type, warehouse
            // types, default parcel. Shapeless on purpose: each ShippingProvider
            // owns and validates its own keys via normalizeConfig().
            $table->jsonb('provider_config')->nullable();

            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        DB::table('m_shipping_methods')->insert([
            ['name' => '{"en":"Courier delivery","ru":"Курьером"}', 'position' => 0, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => '{"en":"Pickup","ru":"Самовывоз"}', 'position' => 1, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => '{"en":"Arrange with manager","ru":"Обсудить с менеджером"}', 'position' => 2, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('m_shipping_methods');
    }
};
