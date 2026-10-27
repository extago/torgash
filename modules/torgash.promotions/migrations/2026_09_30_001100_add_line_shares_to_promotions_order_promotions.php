<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A promotion on a PLACED order is fixed to its lines (PLAN §40.10/3).
 *
 * `shares` is how its cut was laid over the order's lines when it was granted
 * — `{line key: {"a": amount, "q": quantity}}` — so an edit of the order moves
 * it only by what left: a line taken out takes its share, a quantity cut down
 * takes its part, and nothing added afterwards gets any. `target` says what it
 * was cut from, `goods` or `shipping` (a free delivery is capped by what the
 * delivery costs now). NULL in both is a row written before this: it keeps its
 * sum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_promotions_order_promotions', function (Blueprint $table) {
            $table->string('target', 16)->nullable();
            $table->jsonb('shares')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_promotions_order_promotions', function (Blueprint $table) {
            $table->dropColumn(['target', 'shares']);
        });
    }
};
