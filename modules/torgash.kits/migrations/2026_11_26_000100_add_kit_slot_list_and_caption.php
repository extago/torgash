<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things a slot can now say (PLAN §33.5).
 *
 * `products` — a third kind of slot, "one of THESE products": the SKUs the
 * shop typed, in its order, the first one offered by default. A category or
 * a label is the wrong tool for "these three glasses and no others", and
 * making a label just to name them is a list of products wearing a costume.
 * Kept as the SKUs typed (one text, the catalogue's own `Selection::skus()`
 * reads it), not as ids: it is what the owner wrote and reads back, and a
 * product renamed or re-made under the same SKU stays in its place.
 *
 * `caption` — what the slot's tick says on the card ("Glass", "Case",
 * "Charger") instead of the general "In the set". Content the shop types, so
 * a translatable map; empty means the general words.
 *
 * `show_countdown` — the kit's card counts down to its own `ends_at`, the
 * moment the set's price really stops. Never to "the end of the day": a timer
 * that runs out while the price stays teaches customers the timer is
 * decoration (the promotions module's rule, Promotion::countdownEndsAt()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_kits_slots', function (Blueprint $table) {
            $table->text('products')->nullable();
            $table->jsonb('caption')->nullable();
        });

        Schema::table('m_kits_kits', function (Blueprint $table) {
            $table->boolean('show_countdown')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('m_kits_slots', function (Blueprint $table) {
            $table->dropColumn(['products', 'caption']);
        });

        Schema::table('m_kits_kits', function (Blueprint $table) {
            $table->dropColumn('show_countdown');
        });
    }
};
