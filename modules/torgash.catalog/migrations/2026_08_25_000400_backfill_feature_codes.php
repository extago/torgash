<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fills in `code` for rows created before that column existed (e.g. the
 * "Color"/"Brand" features from the original seed) — code is meant to
 * always be present (see FeaturesController::data()), this is the one-time
 * catch-up for data that predates the rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('m_catalog_features')->whereNull('code')->get() as $feature) {
            $name = json_decode($feature->name, true) ?: [];
            $base = Str::upper(Str::slug($name['en'] ?? $name['ru'] ?? 'FEATURE')) ?: 'FEATURE';
            $code = $this->uniqueCode('m_catalog_features', $base, $feature->id);
            DB::table('m_catalog_features')->where('id', $feature->id)->update(['code' => $code]);
        }

        foreach (DB::table('m_catalog_feature_values')->whereNull('code')->get() as $value) {
            $val = json_decode($value->value, true) ?: [];
            $base = Str::upper(Str::slug($val['en'] ?? $val['ru'] ?? 'VALUE')) ?: 'VALUE';
            $code = $this->uniqueCode('m_catalog_feature_values', $base, $value->id, ['feature_id' => $value->feature_id]);
            DB::table('m_catalog_feature_values')->where('id', $value->id)->update(['code' => $code]);
        }
    }

    protected function uniqueCode(string $table, string $base, int $ignoreId, array $scope = []): string
    {
        $candidate = $base;
        $n = 2;

        while (
            DB::table($table)->where('code', $candidate)->where('id', '!=', $ignoreId)
                ->when($scope, fn ($q) => $q->where($scope))->exists()
        ) {
            $candidate = $base.'-'.$n++;
        }

        return $candidate;
    }

    public function down(): void
    {
        // Data backfill only — not meaningfully reversible.
    }
};
