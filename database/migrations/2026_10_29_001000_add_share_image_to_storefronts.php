<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The picture a link to the storefront shows in a messenger or a social
 * network (og:image) — its home page's, and every page's that has none of
 * its own. A path on the public disk (App\Core\Seo\SharePicture).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->string('share_image')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->dropColumn('share_image');
        });
    }
};
