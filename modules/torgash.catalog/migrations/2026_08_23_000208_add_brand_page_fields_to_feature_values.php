<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// For Feature::purpose === 'brand' values: each becomes a canonical page
// (logo via medialibrary, description, own slug) — mirrors the familiar
// "Extended" feature type, where every variant of a brand-purpose
// characteristic gets its own page with an image, description and product list.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_feature_values', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique()->after('value');
            $table->jsonb('description')->nullable()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_feature_values', function (Blueprint $table) {
            $table->dropColumn(['slug', 'description']);
        });
    }
};
