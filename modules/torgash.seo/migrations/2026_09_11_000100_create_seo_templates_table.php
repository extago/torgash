<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A sentence written once and applied to a thousand pages.
 *
 * ONE table where the add-on this replaces had two entities — a per-page-type
 * setting and a named "pattern" assigned to categories — because they were
 * always the same thing seen twice. A template names what it is about
 * (`subject`) and where it applies (`scope`), and the two cases fall out of
 * that: `scope = global` is the per-type setting, `scope = category:12` is the
 * pattern assigned to a category. The add-on's third case — "a separate pattern
 * for the PRODUCTS in this category" — is just `subject = product` with
 * `scope = category:12`, which is why it needs no column of its own.
 *
 * The nearest scope wins over the global one, walking up the category tree, so
 * a shop states the rule once at the top and refines it where it matters.
 *
 * `mode` is the whole question of who overrules whom:
 *
 *   fill_empty  write only where the record itself says nothing. The default,
 *               and the safe one: what somebody typed by hand survives.
 *   override    write always. For a catalogue whose titles came in from an
 *               import and are worse than the template.
 *   skip        do nothing — which only reads as a feature on a SCOPED row: it
 *               is how one category opts out of the shop-wide template without
 *               anybody having to remember it did.
 *
 * The three texts are jsonb like every other translatable field, because a
 * template is a sentence and a sentence has a language. No foreign key on
 * `storefront_id`: that table is core's, and a module does not own the right to
 * cascade deletes through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_seo_templates', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 32);
            $table->string('scope', 64)->default('global');
            $table->string('mode', 16)->default('fill_empty');
            $table->jsonb('title')->nullable();
            $table->jsonb('description')->nullable();
            $table->jsonb('h1')->nullable();
            $table->unsignedBigInteger('storefront_id')->nullable();
            $table->smallInteger('position')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            // The lookup is always "which template applies to this kind of page
            // in this place", asked once per storefront page.
            $table->index(['subject', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_seo_templates');
    }
};
