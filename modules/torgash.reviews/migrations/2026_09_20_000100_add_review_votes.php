<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Was this review helpful?" (PLAN §32.3), and the external id a review needs
 * to be able to arrive from somewhere else.
 *
 * **A row per vote AND a counter on the review**, which is the one place this
 * engine keeps a number it could compute. Both are needed and they answer
 * different questions: the row is what stops one person voting fifty times and
 * what lets them change their mind, and the counter is what a listing SORTS BY
 * — "most helpful first" over a paginated list is an ORDER BY on a column, and
 * a subquery per row is the N+1 the catalogue refuses everywhere.
 *
 * **Signed in only**, hence `user_id` NOT NULL with no nullable session
 * alternative. The module's own rule for writing a review is that an account
 * is the cheapest honest barrier; a vote counted per session is one a script
 * casts by clearing a cookie, and a helpfulness ranking that can be bought
 * with a loop is worse than no ranking at all.
 *
 * `helpful` is a boolean rather than a score: "yes" and "no" are the two
 * answers the question has, and the pair is what a reader wants to see
 * ("14 of 16 found this helpful").
 *
 * `external_id` is §58's rule applied here — what the OTHER system calls this
 * review. Nullable and unique, matched on BEFORE anything else, which is what
 * lets a catalogue of reviews arrive from another engine once and be updated
 * afterwards instead of duplicated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_reviews_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('m_reviews_reviews')->cascadeOnDelete();
            // No foreign key to users, the rule this module already follows
            // for `author_name`: deleting an account may not delete the record
            // of what it said about a product. The vote is anonymised by the
            // account going, not retracted.
            $table->unsignedBigInteger('user_id');
            $table->boolean('helpful');
            $table->timestamps();

            // One person, one review, one answer — changing your mind updates
            // the row rather than adding a second.
            $table->unique(['review_id', 'user_id']);
        });

        Schema::table('m_reviews_reviews', function (Blueprint $table) {
            $table->unsignedInteger('helpful_yes')->default(0);
            $table->unsignedInteger('helpful_no')->default(0);
            $table->string('external_id')->nullable()->unique();

            // **A review written HERE still needs an account.** `Submit` types
            // the signed-in user's id unconditionally and the form is behind a
            // login, so the public door is exactly as narrow as it was: an
            // account remains the cheapest honest barrier and the answer to
            // "who wrote this" a year later.
            //
            // What is now allowed is a review that was not written here at all
            // — one IMPORTED from the engine a shop is moving off, whose
            // author has no account in this shop and never will. Its answer to
            // "who wrote this" is `author_name` plus the `external_id` beside
            // it, which names the system that does know. Forbidding it would
            // mean a shop's years of reviews cannot come with it, and a
            // product page arriving empty after all that looks like a shop
            // nobody buys from.
            //
            // The unique index is unaffected in the useful direction: Postgres
            // counts NULLs as distinct, so many imported reviews of one
            // product are fine while "one review per person per product" still
            // binds everybody who has an account.
            $table->unsignedBigInteger('user_id')->nullable()->change();

            // What "most helpful first" reads. The count of NO votes is
            // deliberately not in it: a review fourteen people found useful is
            // above one two people did, whatever anybody else thought of it,
            // and subtracting would let a brigade bury a review by voting it
            // down rather than merely failing to raise it.
            $table->index(['product_id', 'status', 'helpful_yes']);
        });
    }

    public function down(): void
    {
        Schema::table('m_reviews_reviews', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'status', 'helpful_yes']);
            $table->dropColumn(['helpful_yes', 'helpful_no', 'external_id']);
        });

        Schema::dropIfExists('m_reviews_votes');
    }
};
