<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Memory — only with a processor" (PLAN §33.7, phase 3).
 *
 * A configurator asks questions that only make sense once an earlier one has
 * been answered, and a shop that cannot say so has to ask all of them at once:
 * a barebones server offers DDR5 modules to somebody who has not chosen a
 * board, and a required group nobody can sensibly answer makes the product
 * unbuyable.
 *
 * TWO COLUMNS, not core's display rules, although the group already carries a
 * pair of those. Those answer a different question and at a different moment:
 * `rules`/`rules_match` decide which PRODUCT a shared group is asked on, once,
 * against a product; this decides whether a question is asked AT ALL right
 * now, against the half-filled form in front of the customer, and is re-asked
 * on every change. Putting the second question in the first pair's columns
 * would make "shown on air conditioners" and "shown once a processor is
 * picked" one list whose rows mean different things.
 *
 * `depends_on_choices` empty means "any answer at all will do", which is the
 * common case and the one a shop reaches for first. Naming choices narrows it
 * to those ("a Windows licence only with an Intel board").
 *
 * The foreign key is `nullOnDelete` on purpose: deleting the processor
 * question must release the memory question, not leave it pointing at a row
 * that no longer exists — which, under the fail-closed reading below, would
 * hide it for ever with nothing on screen to explain why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_options_groups', function (Blueprint $table) {
            $table->foreignId('depends_on_group_id')->nullable()->after('rules_match')
                ->constrained('m_options_groups')->nullOnDelete();
            // Which answers to it count. NULL/[] = any answer at all.
            $table->jsonb('depends_on_choices')->nullable()->after('depends_on_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('m_options_groups', function (Blueprint $table) {
            $table->dropConstrainedForeignId('depends_on_group_id');
            $table->dropColumn('depends_on_choices');
        });
    }
};
