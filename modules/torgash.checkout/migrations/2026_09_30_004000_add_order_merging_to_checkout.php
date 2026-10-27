<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Merging one order into another (PLAN §40.10/4).
 *
 * `merged_into_id` on the order that was poured in: it stays as a shell with
 * its number, its history and a link to where its goods went — the customer's
 * link from yesterday's letter leads there. The `merged` status holds no stock
 * (`none`): the goods did not go back to the shelf, they moved with their lines,
 * and a status that `increase`d would credit them a second time. It is closed —
 * nothing moves an order into it or out of it but merging and undoing one.
 *
 * `m_checkout_order_merges` is the record a merge can be UNDONE from: both
 * orders as they were, and how the poured-in lines were renamed. Undoing is
 * possible until anything at all happens to either order after the merge;
 * `undone_at` marks one that was.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('merged_into_id')->nullable();
            $table->index('merged_into_id');
        });

        Schema::create('m_checkout_order_merges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('into_id');
            $table->unsignedBigInteger('from_id');
            // old line key => new line key, for everything that names a line
            $table->jsonb('keys');
            // both orders' columns before the merge, to put back on undo
            $table->jsonb('into_before');
            $table->jsonb('from_before');
            // what each module moved, in its own words, so it can move it back
            // (`OrderMerge::remember()`): `{module: data}`
            $table->jsonb('moved')->nullable();
            // the last history line of each order the merge itself wrote —
            // anything after it is the point of no return
            $table->unsignedBigInteger('into_log_id')->nullable();
            $table->unsignedBigInteger('from_log_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 190)->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->timestamps();

            $table->index(['into_id', 'id']);
            $table->index('from_id');
        });

        if (! DB::table('m_checkout_order_statuses')->where('code', 'merged')->exists()) {
            DB::table('m_checkout_order_statuses')->insert([
                'code' => 'merged',
                'name' => '{"en":"Merged","ru":"Объединён"}',
                'color' => '#94a3b8',
                'position' => (int) DB::table('m_checkout_order_statuses')->max('position') + 1,
                'is_default' => false,
                'inventory_action' => 'none',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('m_checkout_order_statuses')->where('code', 'merged')->delete();
        Schema::dropIfExists('m_checkout_order_merges');

        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropIndex(['merged_into_id']);
            $table->dropColumn('merged_into_id');
        });
    }
};
