<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the shop DID about it, as opposed to what the customer asked for.
 *
 * Three answers the first table had nowhere to put, each of them named by the
 * owner (§29.4/5):
 *
 * - **where the goods went.** A warranty case is the exception that makes this
 *   a decision rather than a setting: the item goes back to the manufacturer or
 *   into the bin, and adding one to stock would put a broken thing on sale. So
 *   the disposition is taken at the moment of receipt, per return.
 * - **which warehouse it arrived at.** "The quantity is added to the warehouse
 *   it came back TO" — a customer returns to the nearest shop, not to the one
 *   that shipped. Null means "wherever it came from", which is what
 *   OrderStock::restore does on its own from the order's frozen breakdown.
 * - **whether anybody owes anybody.** An exchange at today's prices need not
 *   come to what was paid last month, and the shop settles that case by case;
 *   what the engine must not do is invent a payment. So it is recorded as a
 *   note about money and not as money.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_rma_returns', function (Blueprint $table) {
            // restock | writeoff — decided at receipt, never earlier: what is
            // in the parcel is only known once it is opened.
            $table->string('disposition', 16)->nullable()->after('state');

            // No foreign key, and for the usual reason: this module may not own
            // the warehouses module's table, and a shop may not have one at all.
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('disposition');

            // none | due | owed — nothing more to settle, the customer owes the
            // difference, or the shop does.
            $table->string('surcharge', 16)->nullable()->after('exchange_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('m_rma_returns', function (Blueprint $table) {
            $table->dropColumn(['disposition', 'warehouse_id', 'surcharge']);
        });
    }
};
