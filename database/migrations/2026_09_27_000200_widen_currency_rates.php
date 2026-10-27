<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A rate is not money and has no fixed number of decimal places.
 *
 * `numeric(16,8)` kept eight places AFTER THE POINT, which is plenty for
 * 44.639 hryvnia to the dollar and ruinous for anything worth much more than
 * the books: one dollar is about 0.0000105 BTC, stored as 0.00001050 (three
 * significant digits), and 0.0000001049 became 0.00000010 — five per cent off
 * every price quoted in it. Unconstrained `numeric` is exact at any scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE currencies ALTER COLUMN rate TYPE numeric');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE currencies ALTER COLUMN rate TYPE numeric(16,8)');
    }
};
