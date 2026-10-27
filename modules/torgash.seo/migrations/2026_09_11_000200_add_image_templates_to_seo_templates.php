<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a photograph is called, written the same way a title is.
 *
 * On the SAME row rather than a table of its own, because it is the same
 * decision taken about the same set of pages: a shop that writes "Buy
 * {product}" for its laptop titles wants "{product} photo {image_number}" for
 * their photographs, scoped to the same category and governed by the same
 * "only where nothing is typed in". A second table would have duplicated the
 * scope resolution and then drifted from it.
 *
 * Empty is the normal state: `alt` is a caption somebody wrote, and a template
 * only speaks for the pictures nobody captioned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_seo_templates', function (Blueprint $table) {
            $table->jsonb('image_alt')->nullable()->after('h1');
            $table->jsonb('image_title')->nullable()->after('image_alt');
        });
    }

    public function down(): void
    {
        Schema::table('m_seo_templates', function (Blueprint $table) {
            $table->dropColumn(['image_alt', 'image_title']);
        });
    }
};
