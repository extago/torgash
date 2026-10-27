<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Things that must be true about a shop, and the ones that currently are not.
 *
 * The third part of the guard (PLAN §25.2/3). The argument for it came from the
 * promotions review of 2026-09-07: every bug found there had left DATA that said
 * so — an order whose total was −20.00, a stock of −1, a manager's price in the
 * public feed. Nobody was looking, so nobody knew.
 *
 * Two tables, because they answer two different questions.
 *
 * `m_guard_findings` is what is wrong RIGHT NOW, one row per offending thing.
 * That is the decision that separates this from the errors table next door: an
 * error is an event and has a history, an invariant is a STATE — it is either
 * true at this moment or it is not. So a finding that has been put right is
 * deleted by the next run rather than kept and marked resolved, and a screen
 * that is empty means the shop is sound rather than that somebody tidied up.
 *
 * `m_guard_checks` is what we know about the looking itself: when a check last
 * ran, what it cost, whether it hit its cap, whether it blew up. Without it
 * "no problems" and "never actually looked" are the same empty screen, and they
 * are the two most different answers there are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_guard_findings', function (Blueprint $table) {
            $table->id();

            // The invariant's key, e.g. checkout.order_arithmetic.
            $table->string('key', 190);

            // What violates it. NOT NULL, and a check about the shop as a whole
            // uses a fixed word: a nullable column here would not dedupe under
            // the unique index, and the upsert is the whole mechanism.
            $table->string('entity_id', 190);

            // How the offending thing is named, and the numbers that make it
            // wrong ("total 1200, the lines say 1150") — written at the time,
            // so the row reads without going and fetching anything.
            //
            // Stored as a KEY plus its parameters rather than as a finished
            // sentence, which is the rule the background tasks already learned:
            // the nightly run happens in the application's default language and
            // the person reading the screen may be in another one. A value that
            // is data rather than a sentence — an order number, an sku — is
            // simply a key with no translation and no parameters, and comes
            // back out unchanged.
            $table->string('title');
            $table->jsonb('title_params')->nullable();
            $table->string('detail')->nullable();
            $table->jsonb('detail_params')->nullable();
            $table->string('url')->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');

            // "We know, and it is fine." Survives a re-run because the row
            // survives it; the day the thing stops violating, the row and the
            // exemption go together — which is right, since an exemption for
            // something that is no longer wrong is a trap for later.
            $table->timestamp('ignored_at')->nullable();

            $table->unique(['key', 'entity_id']);
            $table->index(['key', 'ignored_at']);
        });

        Schema::create('m_guard_checks', function (Blueprint $table) {
            $table->string('key', 190)->primary();
            $table->timestamp('last_run_at');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->unsignedInteger('violations')->default(0);

            // The check found its limit's worth and stopped, so "3 problems"
            // has to be printed as "at least 3".
            $table->boolean('truncated')->default(false);

            // A check that threw. Recorded rather than swallowed, and its
            // findings are left exactly as they were: a check that crashed
            // knows nothing, including that the old problems are gone.
            $table->string('error')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_guard_checks');
        Schema::dropIfExists('m_guard_findings');
    }
};
