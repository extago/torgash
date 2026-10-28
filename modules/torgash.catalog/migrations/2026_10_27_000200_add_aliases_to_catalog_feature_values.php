<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The spellings merged into a value (PLAN §60).
 *
 * Merging "темно-синий" into "тёмно‑синий" moves its products and deletes its
 * row — and the next supplier file that says "темно-синий" would make it
 * again. So the merged value's names (every language) and its code stay with
 * the value that took its place: `[{"names": {"ru": "темно-синий"}, "code":
 * "TEMNO-SINIY"}]`. An import finds the value by them, an old filtered address
 * by the code (Features\Spelling, Filters\FilterQuery). An ARRAY, because the
 * order is the order they were merged in and jsonb would sort an object's keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_feature_values', function (Blueprint $table) {
            $table->jsonb('aliases')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_feature_values', function (Blueprint $table) {
            $table->dropColumn('aliases');
        });
    }
};
