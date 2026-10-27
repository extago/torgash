<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which payment method an order was placed with — as a link, beside the name.
 *
 * The order has always kept `payment_method_name`, and it keeps it: an order is
 * a record of what the customer was sold, and it must print the name it was
 * sold under even after the shop renames or deletes the method. That is a
 * snapshot, and snapshots are right.
 *
 * What was wrong is that the snapshot was also the only JOIN. Everything the
 * machinery needs — which gateway takes this order's money, which currencies
 * that method accepts — was found by comparing the frozen string to the current
 * names (`Binding::forMethodName`). Rename "Card online" to "Card" and every
 * order placed before the rename silently loses its gateway: the Pay link stops
 * working, and nothing anywhere says why.
 *
 * So: an id for the machinery, the name for the record. The FK is allowed here
 * because both tables belong to this module, and it nulls on delete rather than
 * blocking it — deleting a method must stay possible, and the order still knows
 * what it was called.
 *
 * The backfill matches on any translation of a method's name, not just the
 * current locale's: the name on the order is in the language the CUSTOMER was
 * shopping in. What it cannot match (a method renamed before today) stays null,
 * and the lookup falls back to the old by-name search for exactly those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->foreignId('payment_method_id')->nullable()->after('payment_method_name')
                ->constrained('m_checkout_payment_methods')->nullOnDelete();
        });

        foreach (DB::table('m_checkout_payment_methods')->get(['id', 'name']) as $method) {
            $names = array_values(array_filter((array) json_decode((string) $method->name, true)));

            if ($names === []) {
                continue;
            }

            DB::table('m_checkout_orders')
                ->whereNull('payment_method_id')
                ->whereIn('payment_method_name', $names)
                ->update(['payment_method_id' => $method->id]);
        }
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('payment_method_id');
        });
    }
};
