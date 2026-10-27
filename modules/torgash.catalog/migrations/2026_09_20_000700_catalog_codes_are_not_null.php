<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Support\Codes;

/**
 * A characteristic and its values always have a code.
 *
 * It was the admin FORM that filled a blank one in, which meant a row written
 * by an import, by 1C, by a seeder or over the API kept a NULL — and a NULL
 * code is a row nothing can match on: `exim.match.*` asks about one column, a
 * readable filtered address is written in codes, and CommerceML's links are
 * keyed by them. The rule now lives on the model
 * (Torgash\Catalog\Support\AssignsCode), so this makes the column say what the
 * engine may now rely on.
 *
 * The backfill is the same three answers the model gives, in the same order:
 * what is already there, the name transliterated in the language it is
 * written in, and — for a name made of punctuation or of a script nothing
 * transliterates — the row's own id, which is a thing a person can look up.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (Feature::whereNull('code')->orWhere('code', '')->get() as $feature) {
            $feature->timestamps = false;
            $feature->code = Codes::unique(
                Codes::fromNames((array) $feature->name) ?: 'FEATURE-'.$feature->id,
                'm_catalog_features',
                $feature->id,
            );
            $feature->saveQuietly();
        }

        foreach (FeatureValue::whereNull('code')->orWhere('code', '')->get() as $value) {
            $value->timestamps = false;
            $value->code = Codes::unique(
                Codes::fromNames((array) $value->value) ?: 'VALUE-'.$value->id,
                'm_catalog_feature_values',
                $value->id,
                ['feature_id' => $value->feature_id],
            );
            $value->saveQuietly();
        }

        Schema::table('m_catalog_features', fn ($table) => $table->string('code')->nullable(false)->change());
        Schema::table('m_catalog_feature_values', fn ($table) => $table->string('code')->nullable(false)->change());

        // The unique index over (feature_id, code) was written partial because
        // a NULL code was a real state and several of them had to coexist.
        // There are none now, and a plain index is one the planner can also use
        // for a lookup by code alone.
        DB::statement('DROP INDEX IF EXISTS m_catalog_feature_values_code_unique');
        DB::statement(
            'CREATE UNIQUE INDEX m_catalog_feature_values_code_unique '.
            'ON m_catalog_feature_values (feature_id, code)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS m_catalog_feature_values_code_unique');
        DB::statement(
            'CREATE UNIQUE INDEX m_catalog_feature_values_code_unique '.
            'ON m_catalog_feature_values (feature_id, code) WHERE code IS NOT NULL'
        );

        Schema::table('m_catalog_feature_values', fn ($table) => $table->string('code')->nullable()->change());
        Schema::table('m_catalog_features', fn ($table) => $table->string('code')->nullable()->change());
    }
};
