<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The sale's own page on the storefront.
 *
 * Until now a promotion was pure arithmetic: prices moved and nothing said
 * why. A sale a shop actually runs has a name customers are told, a page it
 * links to from the newsletter, and a list of what is in it — and the usual
 * deal-of-the-day addon carries exactly these columns beside its promotion
 * (h1, page_title, meta_description; plus its own
 * seo slug), which is the confirmation that the shape is not ours alone.
 *
 * `published` is separate from `enabled` on purpose, and the pair is the
 * whole point: a sale can run without a page (most do — the price simply
 * drops), and a page can outlive the sale (a link in last week's mail must
 * not 404). So `enabled` says whether money moves and `published` says
 * whether there is something to read, and neither implies the other.
 *
 * SEO fields are translatable jsonb like every other entity string here; the
 * slug is not, for the same reason a product's is not — one address per row,
 * because a second one is a second page for search engines to choose between.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_promotions_promotions', function (Blueprint $table) {
            $table->string('slug')->nullable()->unique();
            $table->boolean('published')->default(false);
            $table->boolean('show_countdown')->default(true);
            $table->jsonb('description')->nullable();
            $table->jsonb('page_title')->nullable();
            $table->jsonb('meta_description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_promotions_promotions', function (Blueprint $table) {
            $table->dropColumn(['slug', 'published', 'show_countdown', 'description', 'page_title', 'meta_description']);
        });
    }
};
