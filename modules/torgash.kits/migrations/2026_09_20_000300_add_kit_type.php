<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * What a kit IS — and it turns out to be two different things (PLAN §33.5,
 * revised with the owner 2026-09-20).
 *
 * `simple` is what the module shipped: several SEPARATE lines. The customer
 * gets a convenient way to put four things in the basket at once, and each of
 * them is afterwards an ordinary line they can change or remove. It is "people
 * also buy these", and it carries NO discount.
 *
 * `extended` is one line: the main product, with the rest of the set as PARTS
 * of it (`cart.line.parts`, the seam an option's linked product already uses).
 * Stock, 1C and returns still see goods, because a part that is a product
 * becomes its own order line with `parent_key` — but the basket holds one
 * thing, and the quantity of the set moves as one. This is the type that may
 * carry discounts.
 *
 * **The reason for the split is a hole, and it was measured.** A discounted
 * slot used to be an independent line: a basket with one phone and the line
 * raised to a hundred glasses charged half price for all hundred (measured:
 * 100 × 50.00 instead of 100 × 100.00). The discount was tied to the main
 * product only by a check performed while pricing — "is the phone still in the
 * basket" — and a check cannot express "…and only one glass per phone". As a
 * PART it needs no check at all: a part's quantity is its own times the
 * parent's, and there is no separate line to raise.
 *
 * So the two forms are not a preference. Four combinations exist and one of
 * them must not: separate lines with a discount is the hole, and making the
 * discount fields belong to `extended` is what makes it unconfigurable rather
 * than merely discouraged.
 *
 * `auto_assemble` goes with the same broom. It existed so that a basket which
 * happened to hold the phone and the case earned the kit's discount — which is
 * the hole again, from the other side, and with no main line to hang a part on
 * there is nothing for it to mean under `extended` either.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_kits_kits', function (Blueprint $table) {
            $table->string('type', 20)->default('simple');
        });

        // A kit that gives money away is an `extended` one from now on, and
        // there is no migration path back: leaving it as it was would leave
        // the hole open. Its baskets change shape — four lines become one —
        // and its PLACED orders do not, because an order is a snapshot.
        DB::table('m_kits_kits')
            ->whereIn('id', DB::table('m_kits_slots')
                ->where('enabled', true)
                ->where('discount_type', '!=', 'none')
                ->select('kit_id'))
            ->update(['type' => 'extended']);

        Schema::table('m_kits_kits', function (Blueprint $table) {
            $table->dropColumn('auto_assemble');
        });
    }

    public function down(): void
    {
        Schema::table('m_kits_kits', function (Blueprint $table) {
            $table->boolean('auto_assemble')->default(false);
            $table->dropColumn('type');
        });
    }
};
