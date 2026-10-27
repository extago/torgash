<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What went wrong, grouped.
 *
 * A 500 on the storefront goes to `storage/logs/laravel.log`, which a shop
 * owner will never open — so as far as the shop is concerned it did not happen.
 * That is the whole reason this table exists, and it is the same argument the
 * background-tasks screen already won: there has to be ONE place where the
 * things nobody would otherwise learn about surface.
 *
 * One row per GROUP and not per occurrence, which is the decision that keeps
 * this affordable (§25.3 — what is expensive here is not load, it is table
 * growth). A thousand identical failures are one row with `count` = 1000, the
 * first and last time it happened, and a sample of the most recent one. The
 * fingerprint is what makes two failures "the same"; see Errors\Fingerprint.
 *
 * `resolved_at` is the owner saying "I have dealt with this". It hides the row
 * rather than deleting it, and a fresh occurrence clears it again — a problem
 * that comes back has to come back onto the list, or marking things resolved is
 * just a way of not looking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_guard_errors', function (Blueprint $table) {
            $table->id();

            // sha1 of what makes two failures the same thing. Unique, because
            // the recorder relies on ON CONFLICT to count atomically: two
            // requests failing at the same instant must not race into two rows
            // or a lost increment.
            $table->string('fingerprint', 40)->unique();

            $table->string('level', 16)->index();

            // The exception's class, when it was one. Null for a plain log line
            // somebody wrote with Log::error().
            $table->string('class')->nullable();

            $table->text('message');
            $table->string('file')->nullable();
            $table->unsignedInteger('line')->nullable();

            $table->unsignedInteger('count')->default(1);

            // A sample of the LAST occurrence: the url, the method, who was
            // logged in, the first frames of the trace. Deliberately a sample
            // and not a history — keeping every occurrence's context is how
            // this table becomes the biggest one in the database.
            $table->jsonb('context')->nullable();

            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();

            $table->timestamps();

            // The list is "unresolved, most recent first", and the prune is
            // "everything older than N days".
            $table->index(['resolved_at', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_guard_errors');
    }
};
