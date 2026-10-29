<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record's own picture for a shared link (og:image), from the SEO card —
 * over its natural one (a product's photo, a category's tile). A path on
 * the public disk (App\Core\Seo\SharePicture).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->string('share_image')->nullable();
        });

        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->string('share_image')->nullable();
        });

        Schema::table('m_catalog_feature_values', function (Blueprint $table) {
            $table->string('share_image')->nullable();
        });

        Schema::table('m_catalog_filter_pages', function (Blueprint $table) {
            $table->string('share_image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn('share_image');
        });

        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->dropColumn('share_image');
        });

        Schema::table('m_catalog_feature_values', function (Blueprint $table) {
            $table->dropColumn('share_image');
        });

        Schema::table('m_catalog_filter_pages', function (Blueprint $table) {
            $table->dropColumn('share_image');
        });
    }
};
