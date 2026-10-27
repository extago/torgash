<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A static page — read against the usual pages table of older engines,
 * which is where the shape comes from and where three of its columns stop.
 *
 * Theirs carries `page_type` (T text, L link, F form, B blog post, P poll):
 * one table used as a general content container. Ours is text only. A link is
 * a menu item and we have those; a form is torgash.enquiries, which owns an
 * inbox and statuses a page could not; a blog post and a poll are entities
 * with their own lists and feeds. One row type on five meanings gives a screen
 * where half the fields are never about the thing being edited.
 *
 * Theirs also carries `parent_id`/`id_path` (a tree) and `usergroup_ids` +
 * `localization` + an availability period. We take none of those:
 *
 *  - navigation is torgash.menus' job. A second tree would drift from the
 *    first — a page moved in the menu but not in "the page tree", and the
 *    breadcrumbs then argue with the header. Order in the admin list is
 *    `position`; hierarchy is the menu;
 *  - who may see it and where it applies is core's display rules
 *    (`rules`/`rules_match`, App\Core\Display) — storefront, language,
 *    customer group and visitor in one vocabulary, the same one blocks and
 *    promotions already use, with the same "an unknown rule kind fails
 *    closed";
 *  - an availability period is a rarer want than it looks; a page is either
 *    published or it is not. Two columns bring it back if a shop asks.
 *
 * `slug` is unique and its change writes a 301 through App\Core\Seo\
 * RedirectManager, exactly as a product's or a category's does: the address
 * of the privacy policy ends up in printed material and in other people's
 * links, and it must not become a 404 because someone tidied it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_pages_pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();

            $table->jsonb('title');
            $table->jsonb('body')->nullable();

            // What search engines are told. Both fall back to the title/body,
            // so a shop that never opens the SEO section still gets sane tags.
            $table->jsonb('page_title')->nullable();
            $table->jsonb('meta_description')->nullable();

            $table->boolean('enabled')->default(true);

            // A page can be published and still not belong in the sitemap — a
            // "thank you" page, a landing that a campaign links to directly.
            $table->boolean('in_sitemap')->default(true);

            $table->unsignedInteger('position')->default(0);

            // Core's display rules, same columns and same meaning as a block's.
            $table->jsonb('rules')->nullable();
            $table->string('rules_match', 4)->default('all');

            $table->timestamps();

            $table->index(['enabled', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_pages_pages');
    }
};
