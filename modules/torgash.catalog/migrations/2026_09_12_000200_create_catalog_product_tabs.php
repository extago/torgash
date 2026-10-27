<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabs an admin writes, beside the two the product page has of its own
 * (PLAN §30.10).
 *
 * In the CATALOGUE and not in the blocks module, though a tab looks like a
 * block with a label on it: the strip of labels has to be known BEFORE any of
 * the content is rendered, and the zone hook hands back one string of finished
 * HTML with nothing left to say which part of it was called what. What the two
 * do share is the rule engine — `rules`/`rules_match` are core's display rules,
 * the same columns a block carries, so "only on products in this category" is
 * written once in the engine and answered here for free.
 *
 * `product_id` is the other half of the question, and it is a column rather
 * than a display rule because it is how the tab is EDITED: a care-instructions
 * tab belongs on the product's own form, and a shop-wide delivery tab belongs
 * on the tabs screen. NULL means "every product the rules allow".
 *
 * `sort` interleaves with the built-in tabs (description 10, characteristics
 * 20), so a shop can put its own tab in front of them rather than only after —
 * the default of 50 lands after both, which is what a new tab usually wants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_catalog_product_tabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->nullable()
                ->constrained('m_catalog_products')->cascadeOnDelete();
            $table->jsonb('title');
            $table->jsonb('content')->nullable();
            $table->unsignedSmallInteger('sort')->default(50);
            $table->boolean('enabled')->default(true);
            $table->jsonb('rules')->nullable();
            $table->string('rules_match', 3)->default('all');
            $table->timestamps();

            $table->index(['product_id', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_catalog_product_tabs');
    }
};
