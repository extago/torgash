<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the carrier says about a branch beyond its address — read off the live
 * API rather than guessed at (see the field dump in the log, §27).
 *
 * Three of these change what the picker may offer at all:
 *
 *   status          "Working" or not. Branches close; the carrier says so, with
 *                   a date, so this is a fact to read rather than a gap to
 *                   notice when a customer's parcel has nowhere to go.
 *   deny_to_select  the carrier's own "do not offer this one for pickup".
 *   total_max_weight_kg  a branch can have place = 0 and total = 200, meaning
 *                   the per-parcel limit lives in the OTHER column. Filtering
 *                   on place alone offers a 500 kg parcel a branch that takes
 *                   200 (measured in Kyiv: branch №5).
 *
 * The rest is what a customer wants to see before choosing: today's hours and a
 * phone number. Stored because they arrive in the same response — asking for
 * them separately would be a request per branch — and refreshed with everything
 * else on the town's TTL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_np_warehouses', function (Blueprint $table) {
            $table->string('status', 24)->default('Working');
            $table->boolean('deny_to_select')->default(false);
            $table->unsignedInteger('total_max_weight_kg')->default(0);
            $table->string('phone', 32)->nullable();
            $table->string('postal_code', 12)->nullable();
            // {"reception": {"Monday": "08:00-21:00", ...}, "delivery": {...}}
            $table->jsonb('schedule')->nullable();

            $table->index(['settlement_ref', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('m_np_warehouses', function (Blueprint $table) {
            $table->dropIndex(['settlement_ref', 'status']);
            $table->dropColumn(['status', 'deny_to_select', 'total_max_weight_kg', 'phone', 'postal_code', 'schedule']);
        });
    }
};
