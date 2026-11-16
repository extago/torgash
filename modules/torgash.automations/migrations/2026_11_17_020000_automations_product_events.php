<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rules about products (PLAN §64.2–64.3).
 *
 * - `product_batches` — what a rule about products is ABOUT: every product
 *   that crossed a line in one request or one job, together. An import that
 *   empties three thousand shelves is one row here and one message, not three
 *   thousand. `items` is `[{id, before, after}]` — the stock (or the price)
 *   on either side of the change.
 * - `product_marks` — "this rule spoke of this product then", for a rule that
 *   may not repeat itself more often than its quiet period.
 * - `rules.quiet_minutes` — that period: once per order, enquiry or product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_automations_product_batches', function (Blueprint $table) {
            $table->id();
            $table->string('event');
            $table->jsonb('items');
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
        });

        Schema::create('m_automations_product_marks', function (Blueprint $table) {
            $table->foreignId('rule_id')->constrained('m_automations_rules')->cascadeOnDelete();
            $table->unsignedBigInteger('product_id');
            $table->timestamp('told_at');

            $table->primary(['rule_id', 'product_id']);
        });

        Schema::table('m_automations_rules', function (Blueprint $table) {
            $table->unsignedInteger('quiet_minutes')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('m_automations_rules', fn (Blueprint $table) => $table->dropColumn('quiet_minutes'));
        Schema::dropIfExists('m_automations_product_marks');
        Schema::dropIfExists('m_automations_product_batches');
    }
};
