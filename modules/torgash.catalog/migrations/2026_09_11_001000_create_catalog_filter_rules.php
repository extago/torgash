<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a shop says "make me a page for every make of laptop" without typing
 * three hundred addresses.
 *
 * One characteristic per rule, deliberately: that is what the addon this was
 * read from does, and a rule that multiplied two characteristics together would
 * publish the square of the catalogue. A page combining two filters is written
 * by hand, and there are never many of those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_catalog_filter_rules', function (Blueprint $table) {
            $table->id();
            // The shop's own name for the rule — not translatable and never
            // printed to a visitor: it names a rule, not a page.
            $table->string('name');
            $table->foreignId('feature_id')->constrained('m_catalog_features')->cascadeOnDelete();
            // Empty = every category. A list rather than a pivot because
            // nothing ever queries "which rules mention this category" —
            // the generator walks rules, never the other way round.
            $table->jsonb('category_ids')->nullable();
            $table->boolean('with_children')->default(true);
            // `{filter}` and `{category}`, slugified. The address is the one
            // thing a generated page may not change later, so it is stated
            // once here rather than recomputed on every run.
            $table->string('slug_template')->default('{filter}');
            // What the page is CALLED — its link text and its crumb, per
            // language, with the same tokens. Title, description and H1 are
            // NOT here: those are what `torgash.seo`'s templates are for, and
            // two template engines writing the same head is the thing this
            // whole arc exists to prevent.
            $table->jsonb('page_name')->nullable();
            // A page with two products in it is a thin page, and a thousand of
            // them is how a shop gets its catalogue demoted.
            $table->unsignedInteger('min_products')->default(1);
            $table->boolean('in_sitemap')->default(true);
            $table->boolean('enabled')->default(true);
            $table->integer('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_catalog_filter_rules');
    }
};
