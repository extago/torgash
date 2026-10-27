<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Lets a "select" feature (e.g. Color) render swatches instead of plain text pills. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->boolean('is_color')->default(false)->after('input_type');
        });

        Schema::table('m_catalog_feature_values', function (Blueprint $table) {
            $table->string('swatch', 7)->nullable()->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_feature_values', function (Blueprint $table) {
            $table->dropColumn('swatch');
        });
        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->dropColumn('is_color');
        });
    }
};
