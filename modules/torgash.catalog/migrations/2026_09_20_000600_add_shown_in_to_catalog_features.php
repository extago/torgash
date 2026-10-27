<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a characteristic is shown, as a list of place keys — see
 * Torgash\Catalog\Features\Places for why it is a list and not three columns,
 * and why it says nothing about whether the characteristic can be FILTERED by.
 *
 * `purpose` answered two questions at once and loses the one that was never
 * about what a characteristic IS: `info` was "a spec that is not in the
 * filters", which is this column with `filters` unticked. The three values it
 * keeps — plain, variation axis, brand — are the three different things a
 * characteristic can be.
 *
 * The backfill reproduces exactly what each row did before: a `filter` or
 * `brand` one was offered in the panel, printed among the specifications and
 * eligible for the short list under the buy button; `info` and `variation` were
 * everything but the panel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_features', function (Blueprint $table) {
            // Defaulted in the DB *and* in the model (see Feature::$attributes):
            // Postgres does not read a column default back after INSERT, so a
            // freshly created row would otherwise answer NULL until something
            // calls fresh() — the same gotcha availability_status hit.
            $table->jsonb('shown_in')->default('["filters","specs","buy_box"]')->after('purpose');
        });

        DB::statement(<<<'SQL'
            UPDATE m_catalog_features
               SET shown_in = CASE
                     WHEN purpose IN ('info', 'variation') THEN '["specs","buy_box"]'::jsonb
                     ELSE '["filters","specs","buy_box"]'::jsonb
                   END
        SQL);

        // "Display-only" is now the absence of the filters tick; the row is an
        // ordinary characteristic again.
        DB::table('m_catalog_features')->where('purpose', 'info')->update(['purpose' => 'filter']);
    }

    public function down(): void
    {
        DB::statement(<<<'SQL'
            UPDATE m_catalog_features
               SET purpose = 'info'
             WHERE purpose = 'filter'
               AND NOT (shown_in @> '["filters"]'::jsonb)
        SQL);

        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->dropColumn('shown_in');
        });
    }
};
