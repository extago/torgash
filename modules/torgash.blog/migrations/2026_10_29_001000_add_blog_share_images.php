<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An article's or a rubric's own picture for a shared link (og:image),
 * from the SEO card — over an article's cover. A path on the public disk
 * (App\Core\Seo\SharePicture).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_blog_posts', function (Blueprint $table) {
            $table->string('share_image')->nullable();
        });

        Schema::table('m_blog_rubrics', function (Blueprint $table) {
            $table->string('share_image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_blog_posts', function (Blueprint $table) {
            $table->dropColumn('share_image');
        });

        Schema::table('m_blog_rubrics', function (Blueprint $table) {
            $table->dropColumn('share_image');
        });
    }
};
