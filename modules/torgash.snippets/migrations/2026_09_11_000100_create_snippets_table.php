<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A piece of code, a place on the page, and when to print it.
 *
 * Its own module rather than part of `torgash.seo`, and that is the owner's
 * decision (PLAN §30.3/2): every shop puts a counter in, and analytics must not
 * disappear the day somebody uninstalls an SEO module. Nor is it an HTML block —
 * a `<script>` inside a content block is a trap for whoever is editing the
 * texts, and a block has a zone rather than a place in the document head.
 *
 * `rules` / `rules_match` are CORE's engine, the same one the blocks and pages
 * use (App\Core\Display). That is what makes "the pixel only on the thank-you
 * page" and "the counter everywhere but the admin" expressible without a single
 * new entity: the engine already knows how to ask about the route, the
 * language, the storefront and the visitor.
 *
 * The code is printed VERBATIM — that is the whole point of the feature — which
 * is why the module carries its own permission and why every change to this
 * table is picked up by the guard's audit for free (it listens to Eloquent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_snippets_snippets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // head | body_start | footer — the three slots core's theme prints.
            $table->string('placement', 16)->default('head');
            $table->text('code');
            $table->jsonb('rules')->nullable();
            $table->string('rules_match', 3)->default('all');
            // No foreign key: `storefronts` is core's table, and a module does
            // not own the right to cascade deletes through it.
            $table->unsignedBigInteger('storefront_id')->nullable();
            $table->smallInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['enabled', 'placement']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_snippets_snippets');
    }
};
