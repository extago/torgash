<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "The page exists" and "the category links to it" are two questions.
 *
 * Until now they were one column: `enabled` off took the address down with the
 * link, so a shop publishing a page per town had no way to keep two hundred
 * live addresses out of a strip of two hundred buttons above its grid. A city
 * page earns its traffic from a search engine, not from the category it is
 * already inside — but a shop selling cement in one region wants exactly those
 * links, which is why this is a tick box per page and not a rule about geo.
 *
 * On the RULE as well, because the rule is where a shop decides it in one act;
 * it is copied onto each page at CREATION and never rewritten afterwards
 * (unlike `in_sitemap`, which is recomputed because thinning is measured, not
 * editorial). A page taken out of the strip by hand must not come back on the
 * next run.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_filter_pages', function (Blueprint $table) {
            $table->boolean('in_list')->default(true)->after('in_sitemap');
        });

        Schema::table('m_catalog_filter_rules', function (Blueprint $table) {
            $table->boolean('in_list')->default(true)->after('in_sitemap');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_filter_pages', function (Blueprint $table) {
            $table->dropColumn('in_list');
        });

        Schema::table('m_catalog_filter_rules', function (Blueprint $table) {
            $table->dropColumn('in_list');
        });
    }
};
