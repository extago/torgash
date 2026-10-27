<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Options: the questions a product asks before it can be bought (PLAN §33.4).
 *
 * A GROUP is one question — "Processor", "Licences", "Installation" — and how
 * it is asked (one of, any of, a quantity each, rows you add). `product_id`
 * says whose question it is: a product's own, or nobody's in particular, in
 * which case core's display rules (`rules`/`rules_match`, the same two columns
 * a block carries) say where it applies. A shared group is NEVER re-priced per
 * product — that is exactly the confusion the owner asked to be rid of
 * (§33.8): its price is a FORMULA read against whatever product it is asked on,
 * and a product needing its own number gets its own group.
 *
 * A CHOICE is an answer. Three kinds, and the difference is what the shop is
 * actually selling:
 *
 * - `plain` — a name and a price rule. A service, a warranty, an engraving.
 *   Nothing is taken off a shelf, and the money folds into the line's price.
 * - `product` — a real product. Its name, photograph, link and availability
 *   are the PRODUCT's, and in an order it becomes a line of its own so the
 *   warehouse, 1C and a return all see the goods they deal in.
 * - `source` — not one product but a set of them, from the catalogue's own
 *   product-source registry (a category with its subtree, a label, a filter),
 *   offered as a searchable list. Each behaves as `product` once chosen.
 *
 * Money follows the engine's rule without exception: `amount` is the shop's
 * books, `amount_original` + `currency` remember what was typed when it was
 * typed in another currency, and the books column is DERIVED — recomputed on
 * save and on every rate change through `Torgash\Catalog\Pricing\Prices`. A
 * `percent` is a number, not money, and needs none of that.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_options_groups', function (Blueprint $table) {
            $table->id();
            // Whose question this is. NULL = a shared group, placed by rules.
            $table->foreignId('product_id')->nullable()
                ->constrained('m_catalog_products')->cascadeOnDelete();
            $table->jsonb('name');
            $table->jsonb('hint')->nullable();
            // What the shop calls it in its own lists, where six "Colour"s are
            // otherwise indistinguishable — the labels' own trick.
            $table->string('admin_name');
            $table->string('type', 20)->default('select');
            $table->boolean('required')->default(false);
            $table->unsignedSmallInteger('min_select')->nullable();
            $table->unsignedSmallInteger('max_select')->nullable();
            $table->unsignedInteger('min_qty')->default(1);
            $table->unsignedInteger('max_qty')->nullable();
            // Per unit of the product (two servers, twice the processors) or
            // once for the line (one installation, however many you buy).
            $table->boolean('per_unit')->default(true);
            // The quick quantity buttons on the owner's own mock-up ("1x 2x").
            $table->jsonb('qty_steps')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('enabled')->default(true);
            // Where a shared group applies — core's display rules, so a module
            // that knows nothing about options can still describe "in this
            // category" or "with this characteristic".
            $table->jsonb('rules')->nullable();
            $table->string('rules_match', 10)->default('all');
            $table->timestamps();

            $table->index(['product_id', 'sort']);
            $table->index(['enabled', 'sort']);
        });

        Schema::create('m_options_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('m_options_groups')->cascadeOnDelete();
            $table->string('kind', 20)->default('plain');
            $table->jsonb('name')->nullable();
            // A product choice keeps the LINK, never a copy of the name or the
            // price: the product is the answer, and it is allowed to change.
            $table->foreignId('product_id')->nullable()
                ->constrained('m_catalog_products')->nullOnDelete();
            // One value, `kind:arg`, exactly as a products block stores it.
            $table->string('source')->nullable();
            $table->string('price_rule', 30)->default('fixed');
            $table->bigInteger('amount')->default(0);
            $table->bigInteger('amount_original')->nullable();
            $table->char('currency', 3)->nullable();
            $table->decimal('percent', 8, 3)->nullable();
            // What a plain choice weighs, when it is a thing rather than a
            // service: a configured server has to be shippable. NULL is "not
            // measured", the same answer a product's own weight gives.
            $table->unsignedInteger('weight')->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('max_qty')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['group_id', 'sort']);
            $table->index('product_id');
            $table->index('currency');
        });

        // "This shared group does not apply to this product after all." A row
        // rather than a column on the product: it is this module's opinion, and
        // uninstalling has to take it with it.
        Schema::create('m_options_group_exclusions', function (Blueprint $table) {
            $table->foreignId('group_id')->constrained('m_options_groups')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('m_catalog_products')->cascadeOnDelete();

            $table->primary(['group_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_options_group_exclusions');
        Schema::dropIfExists('m_options_choices');
        Schema::dropIfExists('m_options_groups');
    }
};
