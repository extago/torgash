<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A warehouse becomes addressable: which town it is in, whether a customer may
 * collect an order from it, and how to reach it.
 *
 * The retail shop is NOT a second entity beside the warehouse (PLAN §20.2): a
 * separate "stores" table would be a second place holding quantities, and the
 * quantity already lives in m_warehouse_product_stock. So a shop is a warehouse
 * with is_pickup set, and a fulfilment centre is one without — the same
 * distinction Nova Poshta's own directory makes between a branch you collect
 * from and a depot you never see (§31 of the log).
 *
 * `place_id` is deliberately not a foreign key: the locations registry is an
 * optional module and this one may not depend on its table. It is the same
 * column checkout carries on an order, and it answers two questions at once —
 * where the customer collects from, and where the parcel LEAVES from when a
 * carrier prices it (the "origin" of PLAN §12.12/9, which until now nothing
 * could fill).
 *
 * `is_public` and `is_pickup` are separate on purpose. Public means "customers
 * are told about this location at all" — it is what the catalogue's
 * availability filter will read (§20.3); pickup means "an order can be handed
 * over here". A shop that shows its stock but does no click & collect is the
 * first without the second, and a dark store is neither.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_warehouses', function (Blueprint $table) {
            $table->unsignedBigInteger('place_id')->nullable()->after('address');
            $table->boolean('is_public')->default(false)->after('is_default');
            $table->boolean('is_pickup')->default(false)->after('is_public');
            $table->string('phone')->nullable()->after('is_pickup');
            // {"mon": ["09:00", "18:00"], "sun": null} — a missing or null day
            // is a closed day. A lunch break is not representable and is not
            // pretended to be: a second range per day is a schema change, not a
            // convention to smuggle into the same array.
            $table->jsonb('hours')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('m_warehouses', function (Blueprint $table) {
            $table->dropColumn(['place_id', 'is_public', 'is_pickup', 'phone', 'hours']);
        });
    }
};
