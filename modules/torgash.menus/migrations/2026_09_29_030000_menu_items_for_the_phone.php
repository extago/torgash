<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The phone's two menus (PLAN §39.6): everything a phone shows is editable.
 *
 * `note` is the small line under an entry ("Products on sale") — what the
 * drawer's typed links carried after a `|`, now a field of its own, and
 * translatable like the name.
 *
 * Two menus the theme uses from the start, created only when missing:
 * `tabbar` (the bottom bar — shortcuts: home, catalogue, wishlist, basket,
 * profile, the five the automatic bar showed) and `mobile-links` (the drawer's
 * list of links, which used to be a block of typed text). A shortcut or page
 * nobody answers for draws nothing, so a shop without a wishlist simply has
 * four tabs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_menu_items', function (Blueprint $table) {
            $table->jsonb('note')->nullable();
        });

        $this->seed('tabbar', ['en' => 'Phone: bottom bar', 'ru' => 'Телефон: нижняя панель'], [
            ['kind' => 'shortcut', 'target' => 'home'],
            ['kind' => 'shortcut', 'target' => 'catalog'],
            ['kind' => 'shortcut', 'target' => 'wishlist'],
            ['kind' => 'shortcut', 'target' => 'cart'],
            ['kind' => 'shortcut', 'target' => 'account'],
        ]);

        $this->seed('mobile-links', ['en' => 'Phone: links in the menu', 'ru' => 'Телефон: ссылки в меню'], [
            ['kind' => 'page', 'target' => 'route:promotions.index', 'icon' => 'badge-percent', 'highlight' => true,
                'note' => ['en' => 'Products on sale', 'ru' => 'Товары со скидкой']],
            ['kind' => 'page', 'target' => 'route:catalog.brands.index',
                'note' => ['en' => 'Makers and brands', 'ru' => 'Каталог марок и брендов']],
            ['kind' => 'shortcut', 'target' => 'wishlist'],
            ['kind' => 'shortcut', 'target' => 'compare'],
        ]);
    }

    /** @param list<array<string, mixed>> $items */
    protected function seed(string $code, array $name, array $items): void
    {
        if (DB::table('m_menus')->where('code', $code)->exists()) {
            return;
        }

        $now = now();
        $menuId = DB::table('m_menus')->insertGetId([
            'code' => $code,
            'name' => json_encode($name, JSON_UNESCAPED_UNICODE),
            'enabled' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ($items as $sort => $item) {
            DB::table('m_menu_items')->insert([
                'menu_id' => $menuId,
                'name' => '{}',
                'kind' => $item['kind'],
                'target' => $item['target'],
                'icon' => $item['icon'] ?? null,
                'highlight' => $item['highlight'] ?? false,
                'note' => isset($item['note']) ? json_encode($item['note'], JSON_UNESCAPED_UNICODE) : null,
                'sort' => ($sort + 1) * 10,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('m_menu_items', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
