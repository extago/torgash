<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Addresses the shop has stopped talking to — or would have, if it were doing
 * this for real (PLAN §25.2/4).
 *
 * ONE ROW PER ADDRESS, updated, rather than one per incident. That is the same
 * decision the errors table made and the opposite of the audit trail's, and for
 * the same reason: "this address has been back four times" is one fact about
 * one address, and a table with a row per incident would be the biggest one in
 * the database precisely when the shop is under attack — which is when a write
 * amplification is least affordable.
 *
 * So the history lives in columns rather than in rows: `strikes` counts how
 * many times the address earned a block, `signals` says what it was doing,
 * `hits` counts the requests seen since — and in watch mode that last number is
 * the whole point, because it is the answer to "what would this have done to my
 * shop if I had switched it on".
 *
 * `mode` is stamped on the ROW and never changes by itself. A shop that turns
 * enforcement on does not want last week's observations to start refusing
 * people retroactively; what it wants is the next one to bite, and the button
 * on the screen for any older one it agrees with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_guard_blocks', function (Blueprint $table) {
            $table->id();

            // 45 characters: an IPv6 address with an IPv4 tail is the longest
            // thing that can arrive here. Unique because the address IS the
            // identity of the row.
            $table->string('ip', 45)->unique();

            // observed | enforced. "Observed" is a block that was written down
            // and not applied, which is what this module does by default.
            $table->string('mode', 10)->default('observed');

            // Which signal tipped it over — a stored KEY (login/form/api/manual),
            // never a sentence: the guard blocks in the application's default
            // language and the screen is read in the reader's.
            $table->string('reason', 20);

            // { login: 6, form: 4 } — what the address had been doing inside the
            // window. Kept because "ten failed passwords" and "ten refused
            // forms" are two different problems with the same count.
            $table->jsonb('signals')->nullable();

            // How many times this address has earned a block. It is also what
            // lengthens the next one: coming back after being let in is the
            // clearest evidence there is that the first block was right.
            $table->unsignedInteger('strikes')->default(1);

            // Requests seen from it since. In watch mode this is the shop's
            // evidence; in enforcing mode it is how hard the door is being
            // pushed.
            $table->unsignedInteger('hits')->default(0);

            // Why, in the words of whoever did it by hand, and who that was.
            // No foreign key, for the reason the audit trail has none: deleting
            // a manager may not delete the record of what they did.
            $table->string('note')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name')->nullable();

            // NULL is "until somebody says otherwise", which only a person can
            // ask for: an automatic block always has an end, because the cost of
            // a wrong permanent one is a customer who never comes back and
            // never tells anybody why.
            $table->timestamp('until')->nullable();

            // Let back in by hand. The row stays — it is how the next block
            // knows this is not the first time.
            $table->timestamp('released_at')->nullable();

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // The screen, and the prune.
            $table->index(['released_at', 'until']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_guard_blocks');
    }
};
