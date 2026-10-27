<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The carrier's own `CategoryOfWarehouse` — "Branch", "Postomat", "DropOff",
 * "Fulfillment" — kept alongside the three-value `category` this module derives
 * from the type UUID.
 *
 * The derived one flattens DropOff and Fulfillment into "branch", so the picker
 * offers them as pickup points; a customer cannot actually collect a parcel
 * from a fulfilment warehouse. Storing the native value lets `offered()` leave
 * those out, and lets the picker label a locker as a locker without inferring
 * it from a UUID.
 *
 * Nullable and unfilled until the town is next synced — `offered()` treats a
 * null as "not one of the excluded kinds", so nothing already cached drops out
 * before its refresh.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_np_warehouses', function (Blueprint $table) {
            $table->string('np_category', 24)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_np_warehouses', function (Blueprint $table) {
            $table->dropColumn('np_category');
        });
    }
};
