<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews: four tables, and each of them exists for a reason the other three
 * cannot cover.
 *
 * A review is about a PRODUCT and says so in a column, rather than through a
 * target registry like the comments module's. Everything that makes a review
 * different from a comment is a product idea — the criteria a shop rates by,
 * the verified purchase, the average printed on a card — so a registry here
 * would be a generalisation with exactly one member and a pile of code that
 * cannot answer "did this person buy it".
 *
 * The criteria are ROWS, not a setting: a criterion has a name in every
 * language the shop speaks, and settings are one-language (the same reason a
 * product section's heading is a column). Which of them apply where is an
 * ordinary category link — attached to nothing means everywhere, exactly like a
 * filter characteristic, and attaching one to a branch covers everything under
 * it, because in this engine a category always means itself and its
 * subcategories.
 *
 * The aggregate is its own table rather than two columns on the product: a
 * module may not put columns on another module's table, and uninstalling
 * reviews must take every trace with it — including the number a card was
 * printing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_reviews_criteria', function (Blueprint $table) {
            $table->id();
            // Stable and short, for a form field and a stored answer that must
            // survive the criterion being renamed.
            $table->string('code', 64)->unique();
            $table->jsonb('name');
            $table->unsignedInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('m_reviews_criterion_category', function (Blueprint $table) {
            $table->id();
            $table->foreignId('criterion_id')->constrained('m_reviews_criteria')->cascadeOnDelete();
            // No FK to the catalogue's table on purpose: this module may not
            // decide what happens to a category when it is deleted. A row
            // pointing nowhere simply matches nothing.
            $table->unsignedBigInteger('category_id');
            $table->unique(['criterion_id', 'category_id']);
            $table->index('category_id');
        });

        Schema::create('m_reviews_reviews', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('product_id');
            // A reply hangs off the review it answers. The shop's public
            // answer to a bad review belongs under it, not in a second list.
            $table->unsignedBigInteger('parent_id')->nullable();

            // NOT nullable: a guest never writes a review here. It is the
            // cheapest honest barrier there is, and it is what lets a shop
            // answer "who wrote this" a year later.
            $table->unsignedBigInteger('user_id');
            // A snapshot, like the audit keeps of an actor's name: a review
            // printed for years must not lose its author because an account
            // was renamed or closed.
            $table->string('author_name');

            $table->text('body')->nullable();
            $table->text('pros')->nullable();
            $table->text('cons')->nullable();

            // One decimal, because it can be an average of several criteria —
            // "4.3" is what the reader is shown and what the page's structured
            // data says, so it is stored rather than recomputed differently in
            // three places.
            $table->decimal('rating', 2, 1)->nullable();
            // yes / neutral / no. Nullable: a shop can switch the question off.
            $table->string('recommend', 16)->nullable();

            $table->string('status', 16)->default('pending');
            $table->text('moderation_note')->nullable();
            // Worked out when the review is written and frozen: an order
            // cancelled next year does not retract what was true then.
            $table->boolean('verified')->default(false);
            $table->boolean('is_staff')->default(false);

            $table->unsignedBigInteger('storefront_id')->nullable();
            $table->string('locale', 10)->nullable();
            $table->timestamps();

            $table->index(['product_id', 'status']);
            $table->index('parent_id');
            $table->index('status');
        });

        // One review per person per product — a partial index, because the
        // replies under it share both columns and are not reviews.
        DB::statement('CREATE UNIQUE INDEX m_reviews_one_per_person
            ON m_reviews_reviews (product_id, user_id) WHERE parent_id IS NULL');

        Schema::create('m_reviews_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('m_reviews_reviews')->cascadeOnDelete();
            // No FK: a criterion the shop deletes leaves the reviews it was
            // part of intact — the score stays, the label is simply gone.
            $table->unsignedBigInteger('criterion_id');
            $table->unsignedSmallInteger('value');
            $table->unique(['review_id', 'criterion_id']);
        });

        Schema::create('m_reviews_aggregates', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->primary();
            $table->decimal('rating', 2, 1)->default(0);
            $table->unsignedInteger('reviews_count')->default(0);
            // {"5": 12, "4": 3, ...} — what the bar chart above the list is
            // drawn from, so it costs one row rather than a GROUP BY per page.
            $table->jsonb('spread')->nullable();
            // {criterion_code: average} — the per-criterion line under the
            // overall mark.
            $table->jsonb('criteria')->nullable();
            $table->unsignedInteger('recommend_yes')->default(0);
            $table->unsignedInteger('recommend_neutral')->default(0);
            $table->unsignedInteger('recommend_no')->default(0);
            $table->timestamps();

            // "Best rated first" is a sort waiting to be registered.
            $table->index(['rating', 'reviews_count']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_reviews_aggregates');
        Schema::dropIfExists('m_reviews_ratings');
        Schema::dropIfExists('m_reviews_reviews');
        Schema::dropIfExists('m_reviews_criterion_category');
        Schema::dropIfExists('m_reviews_criteria');
    }
};
