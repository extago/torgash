<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A category's picture for a TILE (the "Category tiles" block): a photo of
 * what is inside — a phone, a laptop — where the icon beside it is a sign for
 * a menu line. Two pictures because they answer two places: an icon drawn at
 * 24px and a photo drawn at 128px are not one file at two sizes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->string('tile_image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->dropColumn('tile_image');
        });
    }
};
