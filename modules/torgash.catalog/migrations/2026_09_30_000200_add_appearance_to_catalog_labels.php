<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a label LOOKS, per place — the catalogue card and the product page are
 * different sizes, so one badge size for both is either too loud on a card or
 * lost on the page.
 *
 *   size_list / size_detail     a text badge's step (xs / sm / md / lg); sm is
 *                               what every label was drawn as before
 *   image_height_list/_detail   a picture's height in px; 28 was h-7
 *   image_shape                 as_is / square / rounded / circle — a flag
 *                               drawn from a rectangular file is still a circle
 *   custom_colors               {light: {bg, text}, dark?: {bg, text}}, read
 *                               only while `color` is `custom`; a theme colour
 *                               follows the theme by itself, a hex does not
 *   tooltip                     translatable text shown over the badge
 *                               ("French brand") — a typical sticker add-on
 *                               calls it the sticker's description
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_labels', function (Blueprint $table) {
            $table->string('size_list', 4)->default('sm');
            $table->string('size_detail', 4)->default('sm');
            $table->unsignedSmallInteger('image_height_list')->default(28);
            $table->unsignedSmallInteger('image_height_detail')->default(28);
            $table->string('image_shape', 16)->default('as_is');
            $table->jsonb('custom_colors')->nullable();
            $table->jsonb('tooltip')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_labels', function (Blueprint $table) {
            $table->dropColumn(['size_list', 'size_detail', 'image_height_list', 'image_height_detail', 'image_shape', 'custom_colors', 'tooltip']);
        });
    }
};
