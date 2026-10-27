<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A label drawn as a TILE — a square with a picture and its words under it,
 * the pictogram ("RAM / 32 GB") — takes its picture from the label's own
 * upload (SVG included) or, with none, from a Lucide icon NAMED here: a shop
 * gets a good-looking set of pictograms without drawing a single file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_labels', function (Blueprint $table) {
            $table->string('icon', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_labels', function (Blueprint $table) {
            $table->dropColumn('icon');
        });
    }
};
