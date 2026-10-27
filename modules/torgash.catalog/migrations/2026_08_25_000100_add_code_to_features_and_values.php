<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stable, admin-assigned identity for features/values — distinct from the
 * auto-increment id, modeled on Bitrix's CODE/XML_ID: the exim import
 * matches on this, not on translated name (some engines have such a code
 * column and never actually read it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->string('code')->nullable()->unique()->after('group_id');
        });

        Schema::table('m_catalog_feature_values', function (Blueprint $table) {
            $table->string('code')->nullable()->after('feature_id');
        });

        // Unique per feature, not globally — "WHITE" may reasonably exist
        // under two different features. Partial index so multiple NULLs
        // (no code assigned) never collide.
        DB::statement(
            'CREATE UNIQUE INDEX m_catalog_feature_values_code_unique '.
            'ON m_catalog_feature_values (feature_id, code) WHERE code IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS m_catalog_feature_values_code_unique');
        Schema::table('m_catalog_feature_values', function (Blueprint $table) {
            $table->dropColumn('code');
        });
        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};
