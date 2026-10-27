<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Volumetric weight, as a divisor and a choice — not as a formula language.
 *
 * Every carrier's "formula" is the same one: (L × W × H in cm) ÷ a divisor.
 * Nova Poshta and DHL use 4000, some international tariffs 5000. What actually
 * differs between shops is which weight they are billed on:
 *
 *   actual      — what the scales say (the default, today's behaviour);
 *   volumetric  — the box, whatever it weighs (light bulky goods);
 *   chargeable  — the greater of the two, which is what carriers really do.
 *
 * So two columns instead of an expression parser: the existing weight bands
 * then price whichever weight was chosen, and everything stays exportable and
 * translatable (PLAN §12.5).
 *
 * On the METHOD rather than the rate the plan sketched: the divisor is how a
 * carrier measures, not where it delivers, and repeating it per zone is the
 * kind of copying this admin was just cleaned up to avoid. A per-zone override
 * (domestic 4000 vs international 5000) is a nullable pair of columns on the
 * rate the day a shop needs it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_shipping_methods', function (Blueprint $table) {
            $table->string('charge_on', 12)->default('actual')->after('on_incalculable');
            $table->unsignedSmallInteger('volumetric_divisor')->default(4000)->after('charge_on');
        });
    }

    public function down(): void
    {
        Schema::table('m_shipping_methods', function (Blueprint $table) {
            $table->dropColumn(['charge_on', 'volumetric_divisor']);
        });
    }
};
