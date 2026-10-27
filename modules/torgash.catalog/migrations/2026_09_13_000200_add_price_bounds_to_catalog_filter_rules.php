<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The third axis a generator rule can write pages per (PLAN §30.14): a LIST of
 * boundaries the shop types, never quantiles computed from the catalogue — a
 * boundary that moved with the catalogue would rewrite an address that already
 * went out. Each row is `{"min": minor|null, "max": minor|null}`, in the shop's
 * own currency, one nullable end meaning "up to" / "from".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_filter_rules', function (Blueprint $table) {
            $table->jsonb('price_bounds')->nullable()->after('place_ids');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_filter_rules', function (Blueprint $table) {
            $table->dropColumn('price_bounds');
        });
    }
};
