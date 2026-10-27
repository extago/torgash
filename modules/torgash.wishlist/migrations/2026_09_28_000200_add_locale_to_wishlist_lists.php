<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who a letter about a list is written FOR (PLAN §35.3, step 2).
 *
 * A price drop is noticed by a nightly job and a return by a queue worker —
 * neither has a request to read a language or a shop from, and an account has
 * no language of its own. So the list keeps the ones the customer was using
 * when they last touched it (a heart, a switch): the letter is in that
 * language, its links in that locale's shape, its money in that storefront's
 * currency. `storefront_id` without a cascade: deleting a storefront must not
 * delete somebody's favourites.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE m_wishlist_lists ADD COLUMN locale varchar(10) NULL');
        DB::statement('ALTER TABLE m_wishlist_lists ADD COLUMN storefront_id bigint NULL REFERENCES storefronts(id) ON DELETE SET NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE m_wishlist_lists DROP COLUMN IF EXISTS storefront_id');
        DB::statement('ALTER TABLE m_wishlist_lists DROP COLUMN IF EXISTS locale');
    }
};
