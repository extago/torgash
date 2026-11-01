<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A catalog promotion's money value is a price per unit, which may be kept
 * with four places (PLAN §65): `integer` stops at 214 748 of the currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE m_promotions_tiers ALTER COLUMN discount_value TYPE bigint');
    }

    public function down(): void
    {
        // Wide is a superset of narrow: nothing to put back.
    }
};
