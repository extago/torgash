<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Popularity: ONE number a shop can sort by and can also type over.
 *
 * `views_count` and `sales_count` were already here and stay exactly as they
 * are — they are statistics the labels read (`[sales]`, the "Hit" badge), and
 * `sales_count` counts UNITS, so an order of fifty screws outranks a laptop
 * three people chose. This column answers the other question: how much this
 * product is wanted, in points, with the weight of each event a shop setting
 * (`popularity_view_points` / `popularity_order_points`, 0 = that event does
 * not count).
 *
 * Its own column rather than an expression over those two because the owner
 * asked to be able to "turn it up a bit" by hand: editing `sales_count` to
 * push a product up the listing would falsify a statistic the badges are
 * already reading. Existing products start at 0 rather than being back-filled
 * — the old counters cannot be converted honestly (units against orders), and
 * the points accumulate on their own within days.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->unsignedInteger('popularity')->default(0);

            // The sort reads it descending over the whole catalogue, which is
            // exactly what an index answers.
            $table->index('popularity');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropIndex(['popularity']);
            $table->dropColumn('popularity');
        });
    }
};
