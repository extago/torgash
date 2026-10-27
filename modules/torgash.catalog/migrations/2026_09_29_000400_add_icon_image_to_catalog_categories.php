<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A category's OWN picture for its icon, beside the Lucide name: a shop's
 * departments are often drawn in its own style, and two thousand line icons
 * do not have "fishing tackle". A path on the public disk, in a column — not
 * a media collection: one small file per category, read with the category
 * row the page already has, so the menus and the home page pay no query for
 * it. When both are set, the picture wins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->string('icon_image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->dropColumn('icon_image');
        });
    }
};
