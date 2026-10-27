<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Pages become a tree, and their address moves to the root.
 *
 * Yesterday's migration argued that navigation is the menu's job and a second
 * hierarchy would drift from it. That is right about the MENU ("where can I go
 * from anywhere") and wrong about a SECTION ("where am I, and what else is
 * here"): an information centre — delivery, terms, warranty — has a
 * navigation, a sidebar and breadcrumbs, and all three are derived from the
 * parent. Expressing that with menu items would keep the section's structure
 * in two places and hope nobody forgets one.
 *
 * `path` is MATERIALISED (`info/delivery`) rather than assembled from the
 * ancestors at read time: a page is then found by one indexed comparison, and
 * — more to the point — renaming a section has to rewrite every descendant's
 * address anyway, which is exactly where the 301s have to be written. A path
 * computed on the fly would leave those addresses silently broken.
 *
 * The old `/page/{slug}` shape stays alive as a redirect (see routes.php), so
 * links made before this migration keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_pages_pages', function (Blueprint $table) {
            // A deleted parent does not orphan its children — the model lifts
            // them into its own place and recomputes their paths, which is
            // what nullOnDelete would leave half-done.
            $table->foreignId('parent_id')->nullable()->after('id')
                ->constrained('m_pages_pages')->nullOnDelete();

            // Unique like `slug` is: two pages may share a slug under
            // different parents, but never a full address.
            $table->string('path')->nullable()->after('slug');

            // A single page ("privacy policy") is not a section and needs no
            // navigation beside it; a page inside one almost always does.
            $table->boolean('show_section_nav')->default(true);
        });

        // Every page that existed before this is a root page, so its path is
        // its slug. Done in SQL rather than through the model: a migration
        // must not depend on application code that may change under it.
        DB::table('m_pages_pages')->update(['path' => DB::raw('slug')]);

        Schema::table('m_pages_pages', function (Blueprint $table) {
            $table->string('path')->nullable(false)->change();
            $table->unique('path');
            $table->index('parent_id');

            // The slug stops being globally unique: two sections may each have
            // a "delivery" child, and what must not collide is the ADDRESS,
            // which `path` now is. Sibling collisions are still resolved when
            // the admin saves (see PagesController::slug()).
            $table->dropUnique('m_pages_pages_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('m_pages_pages', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropUnique(['path']);
            $table->unique('slug');
            $table->dropColumn(['parent_id', 'path', 'show_section_nav']);
        });
    }
};
