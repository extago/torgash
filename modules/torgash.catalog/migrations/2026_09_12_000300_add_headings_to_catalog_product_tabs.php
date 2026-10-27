<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A section has TWO names, and that is the whole point of this migration.
 *
 * `title` is the word in the strip of tabs — it has room for one word, because
 * six of them stand in a row. `heading` is what stands over the section when
 * the page is laid out as sections instead: a line with room for the product in
 * it ("Відгуки про Прожектор TIGER PRO-300 300W 6400K"), which is exactly what
 * a shop wants there and exactly what will not fit in a tab.
 *
 * `section` is the other half: a row that NAMES one of the page's own sections
 * (`description`, `specs`) carries no content — it supplies that section's
 * words, its position and whether it is shown at all. Without it the two
 * built-in sections would be the only ones a shop could not rename, and they
 * are the two every shop wants to rename, because they are the two that are on
 * every product.
 *
 * Both are nullable and both fall back: no heading means the tab's own title,
 * no row for a built-in section means the words the engine ships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_product_tabs', function (Blueprint $table) {
            $table->jsonb('heading')->nullable()->after('title');
            $table->string('section', 32)->nullable()->after('product_id');
        });

        // One row per built-in section — two rows renaming the same section is
        // a question with two answers. PARTIAL indexes, because a plain unique
        // would not hold: Postgres treats rows as distinct when any indexed
        // column is NULL, and the shop-wide rows are exactly the ones whose
        // product_id is NULL.
        DB::statement('CREATE UNIQUE INDEX m_catalog_product_tabs_global_section
            ON m_catalog_product_tabs (section) WHERE product_id IS NULL AND section IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX m_catalog_product_tabs_product_section
            ON m_catalog_product_tabs (product_id, section) WHERE section IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS m_catalog_product_tabs_global_section');
        DB::statement('DROP INDEX IF EXISTS m_catalog_product_tabs_product_section');

        Schema::table('m_catalog_product_tabs', function (Blueprint $table) {
            $table->dropColumn(['heading', 'section']);
        });
    }
};
