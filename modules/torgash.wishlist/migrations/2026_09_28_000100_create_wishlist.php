<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A wishlist is a ROW addressed by a token, as a basket is (`m_cart_carts`):
 * a guest's list lives by the token in their session, an account's is the one
 * row with its `user_id`, and signing in pours the guest's into the account's
 * (Wishlists::adopt) — so the heart never asks anybody to sign in first.
 *
 * Items are ROWS, not a jsonb bag: "what is wished for most" is a GROUP BY,
 * and a deleted product leaves every list by cascade. A product, never a
 * configuration: "I want this phone" is about the phone; its options are
 * chosen when it is bought.
 *
 * `price_at_add` is the shop's books (minor units) when the product was put
 * there — what a price drop is measured against, and moved to the new price
 * once the drop has been told. `share_token` is issued when the customer asks
 * to share, not before; `notify_price` / `notify_stock` are the customer's
 * own consent, off until ticked.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE TABLE m_wishlist_lists (
                id bigserial PRIMARY KEY,
                token varchar(64) NOT NULL UNIQUE,
                user_id bigint NULL REFERENCES users(id) ON DELETE CASCADE,
                share_token varchar(64) NULL UNIQUE,
                notify_price boolean NOT NULL DEFAULT false,
                notify_stock boolean NOT NULL DEFAULT false,
                last_active_at timestamp(0) NULL,
                created_at timestamp(0) NULL,
                updated_at timestamp(0) NULL
            )
        ");
        DB::statement('CREATE UNIQUE INDEX m_wishlist_lists_user_idx ON m_wishlist_lists (user_id) WHERE user_id IS NOT NULL');

        DB::statement('
            CREATE TABLE m_wishlist_items (
                id bigserial PRIMARY KEY,
                list_id bigint NOT NULL REFERENCES m_wishlist_lists(id) ON DELETE CASCADE,
                product_id bigint NOT NULL REFERENCES m_catalog_products(id) ON DELETE CASCADE,
                price_at_add bigint NULL,
                created_at timestamp(0) NULL,
                UNIQUE (list_id, product_id)
            )
        ');
        DB::statement('CREATE INDEX m_wishlist_items_product_idx ON m_wishlist_items (product_id)');
        DB::statement('CREATE INDEX m_wishlist_items_created_idx ON m_wishlist_items (created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS m_wishlist_items');
        DB::statement('DROP TABLE IF EXISTS m_wishlist_lists');
    }
};
