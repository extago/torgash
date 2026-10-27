<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WHERE a promotion applies, as core's display rules.
 *
 * A shop with several storefronts runs some sales everywhere and some on one
 * of them — the owner's own framing: a storefront is a cover, and what shows
 * on the second one is decided per thing, so a promotion is "global, or for
 * this storefront".
 *
 * Not a `storefront_id` column, for three reasons. One storefront is not the
 * only answer anybody wants ("both wholesale storefronts" is a list). The
 * engine for exactly this question is already core's — `App\Core\Display`,
 * built for one module asking about another's data, with the storefront kind
 * registered and an editor to go with it — so a column here would be a second
 * spelling of it. And it fails CLOSED on a missing context, which is what
 * keeps a storefront-scoped sale out of an export feed, where there is no
 * storefront to be on.
 *
 * The same two columns blocks use, and deliberately the same names: `rules` +
 * `rules_match` is now what "when does this apply" looks like anywhere in the
 * shop. NULL rules means everywhere, which is every promotion that exists
 * today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_promotions_promotions', function (Blueprint $table) {
            $table->jsonb('rules')->nullable();
            $table->string('rules_match', 3)->default('all');
        });
    }

    public function down(): void
    {
        Schema::table('m_promotions_promotions', function (Blueprint $table) {
            $table->dropColumn(['rules', 'rules_match']);
        });
    }
};
