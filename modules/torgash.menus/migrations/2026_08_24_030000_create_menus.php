<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modeled on the usual menu manager. Two ideas carried over deliberately:
 *
 *  1. Several NAMED menus, each an independent tree — established engines keep main menu,
 *     quick links and footer menus side by side. Templates ask for one by
 *     `code`, so a theme never hardcodes ids.
 *
 *  2. An item can generate its own submenu from a category instead of listing
 *     children by hand ("Create submenu: Category", "submenu will
 *     include child elements of the selected object"). That is what makes a
 *     catalog mega menu maintainable: add a subcategory and the menu follows,
 *     without anyone editing the menu.
 *
 * Left out on purpose (they are theme/add-on concerns elsewhere too, and we
 * have no use for them yet): per-item icon, badge/marker with colours, an
 * arbitrary HTML block inside the dropdown, and the three-level mode.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_menus', function (Blueprint $table) {
            $table->id();
            // Templates reference a menu by this, not by id.
            $table->string('code')->unique();
            $table->jsonb('name');
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('m_menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_id')->constrained('m_menus')->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('m_menu_items')->cascadeOnDelete();
            $table->jsonb('name');
            $table->string('url')->nullable();
            $table->boolean('new_tab')->default(false);

            // none | category — where a submenu comes from, if anywhere.
            $table->string('source_type')->default('none');
            $table->foreignId('source_category_id')->nullable()
                ->constrained('m_catalog_categories')->nullOnDelete();
            // Take the category's own link/label instead of the ones typed above.
            $table->boolean('use_source_link')->default(true);

            $table->string('css_class')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['menu_id', 'parent_id', 'sort']);
        });

        // The header looks for this code; seed it so the mega menu keeps working
        // the moment the module is installed, with the categories already there.
        $menuId = DB::table('m_menus')->insertGetId([
            'code' => 'catalog',
            'name' => json_encode(['en' => 'Catalog menu', 'ru' => 'Меню каталога'], JSON_UNESCAPED_UNICODE),
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sort = 0;
        foreach (DB::table('m_catalog_categories')->whereNull('parent_id')->whereNull('deleted_at')->orderBy('sort')->get() as $category) {
            DB::table('m_menu_items')->insert([
                'menu_id' => $menuId,
                'parent_id' => null,
                'name' => $category->name,
                'url' => null,
                'new_tab' => false,
                'source_type' => 'category',
                'source_category_id' => $category->id,
                'use_source_link' => true,
                'sort' => $sort += 10,
                'enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('m_menu_items');
        Schema::dropIfExists('m_menus');
    }
};
