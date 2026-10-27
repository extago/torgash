<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift cards (PLAN §40.4). Three tables:
 *
 * - `cards` — one per card. The code is never stored to be read back by a
 *   search: `code_hash` (an HMAC under the app key) finds it, `code_encrypted`
 *   lets the shop resend a letter or print a batch, `last4` names it in lists.
 *   `balance` is DERIVED — the sum of the ledger, rewritten in the same
 *   transaction as every entry — and kept on the row only so a list can show
 *   and sort it.
 * - `ledger` — every movement of a balance, signed, with the order or the
 *   person it came from. The truth; the balance is its sum.
 * - `products` — which catalogue products ARE gift cards, and how their amount
 *   is chosen. A module may not put a column on the catalogue's table.
 *
 * Money is the shop's books, like every money column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_giftcards_cards', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->text('code_encrypted');
            $table->string('last4', 4);
            $table->unsignedBigInteger('initial_amount');
            $table->bigInteger('balance')->default(0);
            // active | pending (printed, not handed over yet) | disabled.
            // Redeemed and expired are worked out, never stored.
            $table->string('status', 16)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->foreignId('owner_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('recipient_name', 190)->nullable();
            $table->string('recipient_email', 190)->nullable();
            $table->string('sender_name', 190)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('deliver_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            // sold | issued | batch
            $table->string('source', 16)->default('issued');
            // The order that bought it: which line and which of that line's
            // cards, so paying the same order twice never issues twice.
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('order_line_key', 64)->nullable();
            $table->unsignedSmallInteger('order_seq')->nullable();
            $table->string('batch', 64)->nullable();
            $table->string('public_token', 48)->unique();
            // Where it may be spent (PLAN §40.4) — empty is everywhere, whole.
            $table->jsonb('rules')->nullable();
            $table->string('locale', 8)->nullable();
            $table->unsignedBigInteger('storefront_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'order_line_key', 'order_seq']);
            $table->index('owner_user_id');
            $table->index('batch');
            $table->index(['deliver_at', 'delivered_at']);
        });

        Schema::create('m_giftcards_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('card_id')->constrained('m_giftcards_cards')->cascadeOnDelete();
            // issue | redeem | refund | adjust | expire | void
            $table->string('type', 16);
            $table->bigInteger('amount');
            // No FK: an order in the trash or deleted must not take the record
            // of what it spent with it.
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 190)->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('card_id');
            $table->index('order_id');
        });

        Schema::create('m_giftcards_products', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained('m_catalog_products')->cascadeOnDelete();
            // The customer types the amount (else the product's price IS it).
            $table->boolean('free_amount')->default(false);
            $table->unsignedBigInteger('min_amount')->nullable();
            $table->unsignedBigInteger('max_amount')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_giftcards_products');
        Schema::dropIfExists('m_giftcards_ledger');
        Schema::dropIfExists('m_giftcards_cards');
    }
};
