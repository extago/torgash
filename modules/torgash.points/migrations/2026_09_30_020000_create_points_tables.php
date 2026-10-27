<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bonus points (PLAN §40.5).
 *
 * - `ledger` — every movement of a customer's points, signed: earn, spend,
 *   refund (spent points given back), revoke (earned points taken back),
 *   adjust, expire. The truth.
 * - `accounts` — the balance, the SUM of the ledger, rewritten in the same
 *   transaction and locked while points are spent. A module may not put a
 *   column on `users`, and uninstalling must take the numbers with it.
 * - `orders` — what an order will earn (planned at placement, shown as
 *   "waiting"), what it did earn, and what was spent on it.
 * - `rules` — multipliers: a category (with its subtree), a product, a
 *   customer group. 0 excludes.
 * - `products` — a product's price in points, when the shop sells for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_points_accounts', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->integer('balance')->default(0);
            $table->timestamps();
        });

        Schema::create('m_points_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            // earn | spend | refund | revoke | adjust | expire
            $table->string('type', 16);
            $table->integer('points');
            $table->unsignedBigInteger('order_id')->nullable();
            // When earned points stop being spendable (null = never).
            $table->timestamp('expires_at')->nullable();
            $table->text('note')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_name', 190)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'id']);
            $table->index('order_id');
        });

        Schema::create('m_points_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('order_id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->integer('earn')->default(0);
            $table->integer('earned')->default(0);
            $table->integer('spent')->default(0);
            $table->unsignedBigInteger('spent_money')->default(0);
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'credited_at']);
        });

        Schema::create('m_points_rules', function (Blueprint $table) {
            $table->id();
            // category | product | group
            $table->string('kind', 16);
            $table->unsignedBigInteger('target_id');
            $table->decimal('multiplier', 6, 2)->default(1);
            $table->timestamps();

            $table->unique(['kind', 'target_id']);
        });

        Schema::create('m_points_products', function (Blueprint $table) {
            $table->foreignId('product_id')->primary()->constrained('m_catalog_products')->cascadeOnDelete();
            $table->unsignedInteger('points_price');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_points_products');
        Schema::dropIfExists('m_points_rules');
        Schema::dropIfExists('m_points_orders');
        Schema::dropIfExists('m_points_ledger');
        Schema::dropIfExists('m_points_accounts');
    }
};
