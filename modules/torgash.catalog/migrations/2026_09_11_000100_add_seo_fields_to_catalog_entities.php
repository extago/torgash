<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a product, a category and a brand say about their own page.
 *
 * COLUMNS on the entity, not a table in the SEO module, and that is the
 * decision the owner took in PLAN §30.3: a meta description is a property of
 * the product. It has to survive `torgash.seo` being uninstalled, reach an
 * export and the API without a line of extra code, and be edited on the same
 * screen as everything else about the product. A generic
 * (object_type, object_id, var, value) table — which is what the add-on
 * this replaces used — gives up all three.
 *
 * `meta_title`, `meta_description` and `h1` are jsonb for the same reason
 * `name` is: a two-language shop writes two of each. `noindex` is a flag the
 * page prints as `<meta name="robots">`, and `canonical_url` is the escape
 * hatch for "this page is a copy of that one" — a free-form address, because
 * the page it points at may not be ours at all.
 *
 * Nullable and empty by default, which matters more than it looks: an empty
 * meta field is not "no title", it is "nobody has said, use what the shop
 * worked out" — see App\Core\Seo\PageMeta.
 */
return new class extends Migration
{
    /** table => the column each set of SEO fields is inserted after. */
    protected array $tables = [
        'm_catalog_products' => 'description',
        'm_catalog_categories' => 'description',
        'm_catalog_feature_values' => 'slug',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table => $after) {
            Schema::table($table, function (Blueprint $blueprint) use ($after) {
                $blueprint->jsonb('meta_title')->nullable()->after($after);
                $blueprint->jsonb('meta_description')->nullable()->after('meta_title');
                $blueprint->jsonb('h1')->nullable()->after('meta_description');
                $blueprint->boolean('noindex')->default(false)->after('h1');
                $blueprint->string('canonical_url')->nullable()->after('noindex');
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->tables) as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn(['meta_title', 'meta_description', 'h1', 'noindex', 'canonical_url']);
            });
        }
    }
};
