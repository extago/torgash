<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A page's own picture for a shared link (og:image), from the SEO card —
 * without one, the storefront's. A path on the public disk
 * (App\Core\Seo\SharePicture).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_pages_pages', function (Blueprint $table) {
            $table->string('share_image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_pages_pages', function (Blueprint $table) {
            $table->dropColumn('share_image');
        });
    }
};
