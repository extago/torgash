<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A category's icon (PLAN §38) — a lucide.dev name, drawn beside it in the
 * phone menu the way every reference shop draws its departments. A NAME, not
 * a file: the pack ships two thousand icons, each already in the engine's own
 * line and weight. NULL = no icon, and the menu simply draws none.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE m_catalog_categories ADD COLUMN icon varchar(64) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE m_catalog_categories DROP COLUMN IF EXISTS icon');
    }
};
