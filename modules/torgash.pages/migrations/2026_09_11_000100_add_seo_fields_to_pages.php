<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The three SEO fields a page did not have: a heading of its own, "keep this
 * one out of the index", and "this is a copy of that address".
 *
 * The two it already had (`page_title`, `meta_description`) keep their names.
 * Renaming them to match the catalogue's would rewrite every page a shop has
 * written for the sake of a word, and App\Core\Seo\PageSubject exists precisely
 * so that two modules can name their columns differently and still be read by
 * one screen.
 *
 * `in_sitemap` is already here and stays a separate question: a page can be
 * worth a link in the sitemap and not worth an index entry, and the reverse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_pages_pages', function (Blueprint $table) {
            $table->jsonb('h1')->nullable()->after('meta_description');
            $table->boolean('noindex')->default(false)->after('h1');
            $table->string('canonical_url')->nullable()->after('noindex');
        });
    }

    public function down(): void
    {
        Schema::table('m_pages_pages', function (Blueprint $table) {
            $table->dropColumn(['h1', 'noindex', 'canonical_url']);
        });
    }
};
