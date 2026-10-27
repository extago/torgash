<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * How many days goods take from this warehouse to a pickup point (PLAN §38,
 * the owner's decision): what "we will bring it by 24.09" is counted from
 * when the shop the customer chose does not hold the product and another
 * warehouse does. NULL = this warehouse does not send to points, so it offers
 * no date at all — the honest answer, rather than an invented one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE m_warehouses ADD COLUMN transfer_days smallint NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE m_warehouses DROP COLUMN IF EXISTS transfer_days');
    }
};
