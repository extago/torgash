<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first migration seeded the `catalog` menu with one item per top
 * category that existed AT INSTALL — which on a new shop is none. The menu
 * then existed and was empty, so the header's "Catalogue" button and the
 * phone's drawer drew nothing and the fallback to the category tree never
 * came, because a menu that exists is the shop's answer (PLAN §39).
 *
 * An EMPTY `catalog` menu gets the one item that needs no upkeep: the whole
 * catalogue, its categories standing in its place, two levels deep — a
 * category added tomorrow is in the menu tomorrow. A menu with items is the
 * shop's and is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $menuId = DB::table('m_menus')->where('code', 'catalog')->value('id');

        if (! $menuId || DB::table('m_menu_items')->where('menu_id', $menuId)->exists()) {
            return;
        }

        DB::table('m_menu_items')->insert([
            'menu_id' => $menuId,
            'name' => '{}',
            'kind' => 'category',
            'source_category_id' => null,
            'options' => json_encode(['depth' => 2, 'replace' => true, 'show_when' => 'always', 'child_icons' => true]),
            'sort' => 10,
            'enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void {}
};
