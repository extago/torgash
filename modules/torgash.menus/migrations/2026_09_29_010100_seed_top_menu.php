<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The light header always ASKED for a menu coded `top` and fell back to two
 * hard-wired links when there was none — which is every shop, since nothing
 * ever created it. It now exists from the start, holding exactly those two
 * links as shop pages (PLAN §39), and the theme places it in the header's top
 * strip through a block, where it can be switched off or moved.
 *
 * A page key nobody answers for draws nothing, so a shop without promotions
 * simply shows "Brands".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('m_menus')->where('code', 'top')->exists()) {
            return;
        }

        $now = now();
        $menuId = DB::table('m_menus')->insertGetId([
            'code' => 'top',
            'name' => json_encode(['en' => 'Top strip', 'ru' => 'Верхняя полоса'], JSON_UNESCAPED_UNICODE),
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $items = [
            ['target' => 'route:promotions.index', 'icon' => 'zap', 'highlight' => true],
            ['target' => 'route:catalog.brands.index', 'icon' => null, 'highlight' => false],
        ];

        foreach ($items as $sort => $item) {
            DB::table('m_menu_items')->insert([
                'menu_id' => $menuId,
                'name' => '{}',
                'kind' => 'page',
                'target' => $item['target'],
                'icon' => $item['icon'],
                'highlight' => $item['highlight'],
                'sort' => ($sort + 1) * 10,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /** The menu is the shop's from the moment it exists. */
    public function down(): void {}
};
