<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kits: several SEPARATE products that are cheaper together (PLAN §33.5).
 *
 * Not options, and the difference is the whole reason this is a second module
 * (§33.2): an option changes what one line IS — delete the server and its
 * processors go with it — while a kit is a phone, a case and a glass that stay
 * three products. Delete the phone and the glass is still in the basket; what
 * goes is the kit's discount.
 *
 * A kit is attached either to ONE main product or to whatever core's display
 * rules say (a category with its subtree, a characteristic), in which case the
 * product whose page it is shown on becomes the main one. That is what makes
 * "every phone gets a glass and a case" one row rather than one per phone.
 *
 * A SLOT is one place in the kit: a fixed product, or a set of products from
 * the catalogue's own source registry with an optional COMPATIBILITY rule —
 * `match_feature_id`, "the same value of this characteristic as the main
 * product has". That is how a glass for the model being looked at is offered
 * rather than a glass for some other phone, with no list of pairs to maintain.
 *
 * The discount lives on the SLOT and is applied to the cart LINE (§33.8), never
 * as one lump on the order: 1C gets a price per line, a return knows what that
 * item actually cost, and the customer sees which of the goods is cheaper.
 * Money follows the engine's rule — `discount_amount` is the books, the typed
 * number and its currency are kept beside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_kits_kits', function (Blueprint $table) {
            $table->id();
            $table->string('admin_name');
            $table->jsonb('name');
            $table->jsonb('description')->nullable();
            // One product's kit, or nobody's — and then the rules place it.
            $table->foreignId('main_product_id')->nullable()
                ->constrained('m_catalog_products')->cascadeOnDelete();
            $table->jsonb('rules')->nullable();
            $table->string('rules_match', 10)->default('all');
            // Where on the product page: under the buy button, or as a section
            // of its own in the strip beside Description.
            $table->string('placement', 20)->default('after_buy_box');
            // The owner's own tick (§33.8): by default a kit's discount is
            // earned by buying it AS a kit, not by a basket that happens to
            // hold the same goods.
            $table->boolean('auto_assemble')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->integer('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['enabled', 'sort']);
            $table->index('main_product_id');
        });

        Schema::create('m_kits_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kit_id')->constrained('m_kits_kits')->cascadeOnDelete();
            $table->string('kind', 20)->default('product');
            $table->foreignId('product_id')->nullable()
                ->constrained('m_catalog_products')->nullOnDelete();
            // One value, `kind:arg`, exactly as a products block stores it.
            $table->string('source')->nullable();
            // "…and only the ones whose value of this characteristic is the
            // main product's own" — compatibility without a table of pairs.
            $table->foreignId('match_feature_id')->nullable()
                ->constrained('m_catalog_features')->nullOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->string('discount_type', 20)->default('percent');
            $table->decimal('discount_percent', 8, 3)->nullable();
            $table->bigInteger('discount_amount')->default(0);
            $table->bigInteger('discount_amount_original')->nullable();
            $table->char('discount_currency', 3)->nullable();
            // A slot the customer cannot untick — the kit is not a kit without
            // it. Everything else is a suggestion with a tick box.
            $table->boolean('required')->default(false);
            // May the customer swap it for another product of the same set.
            $table->boolean('swappable')->default(true);
            $table->integer('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['kit_id', 'sort']);
            $table->index('product_id');
            $table->index('discount_currency');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_kits_slots');
        Schema::dropIfExists('m_kits_kits');
    }
};
