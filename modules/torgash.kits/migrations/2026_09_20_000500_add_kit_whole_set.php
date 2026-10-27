<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Show it only when the whole set is there" (PLAN §33.5).
 *
 * A slot that cannot be filled — no glass fits this model, the one product it
 * named has been disabled — is quietly left out, and the kit is drawn with
 * whatever remains. That is right for "people also buy these" and wrong for a
 * set that was designed as a set: a card headed "Everything for the kitchen"
 * showing two of its four items is an offer the shop never made, at a price
 * the customer reads as the whole thing's.
 *
 * **Two different questions, which is why this is not `required` on every
 * slot.** `required` is about the CUSTOMER — they may not untick it, it is
 * what the kit IS. This is about the SHOP — do not advertise a set that
 * cannot be assembled today. A kit can want one without the other: the case
 * is genuinely optional, and the shop still does not want the card to appear
 * when there is no case to put in it.
 *
 * Off by default, because it is the behaviour every existing kit already has
 * and the narrower reading is the one to opt into.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_kits_kits', function (Blueprint $table) {
            $table->boolean('whole_set_only')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('m_kits_kits', function (Blueprint $table) {
            $table->dropColumn('whole_set_only');
        });
    }
};
