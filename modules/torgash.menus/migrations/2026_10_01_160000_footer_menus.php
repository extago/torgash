<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The footer's two menus (the owner's design, 2026-10-01), created only when
 * missing — the theme's footer places them as blocks by these codes.
 *
 * `footer-buyers` — "For customers": what a fresh shop always has (brands,
 * sale, the blog); the shop adds its info pages. `footer-company` — "About
 * us" and "Legal information" as headings, the second holding the one link
 * every shop has from the start: the cookie choices (`#cookie-settings`). A
 * page nobody answers for draws nothing, so an absent blog leaves no gap.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->seed('footer-buyers', ['en' => 'Footer: for customers', 'ru' => 'Подвал: покупателям'], [
            ['kind' => 'heading', 'name' => ['en' => 'For customers', 'ru' => 'Покупателям'], 'children' => [
                ['kind' => 'page', 'target' => 'route:catalog.brands.index'],
                ['kind' => 'page', 'target' => 'route:promotions.index'],
                ['kind' => 'page', 'target' => 'route:blog.index'],
            ]],
        ]);

        $this->seed('footer-company', ['en' => 'Footer: the company', 'ru' => 'Подвал: о компании'], [
            ['kind' => 'heading', 'name' => ['en' => 'About us', 'ru' => 'О компании'], 'children' => []],
            ['kind' => 'heading', 'name' => ['en' => 'Legal information', 'ru' => 'Правовая информация'], 'children' => [
                ['kind' => 'link', 'url' => '#cookie-settings', 'name' => ['en' => 'Cookie settings', 'ru' => 'Настройки куки']],
            ]],
        ]);
    }

    public function down(): void
    {
        $ids = DB::table('m_menus')->whereIn('code', ['footer-buyers', 'footer-company'])->pluck('id');
        DB::table('m_menu_items')->whereIn('menu_id', $ids)->delete();
        DB::table('m_menus')->whereIn('id', $ids)->delete();
    }

    /** @param  list<array<string, mixed>>  $items */
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

        $this->items($menuId, null, $items, $now);
    }

    /** @param  list<array<string, mixed>>  $items */
    protected function items(int $menuId, ?int $parentId, array $items, $now): void
    {
        foreach ($items as $sort => $item) {
            $id = DB::table('m_menu_items')->insertGetId([
                'menu_id' => $menuId,
                'parent_id' => $parentId,
                'name' => json_encode($item['name'] ?? (object) [], JSON_UNESCAPED_UNICODE),
                'kind' => $item['kind'],
                'target' => $item['target'] ?? null,
                'url' => $item['url'] ?? null,
                'sort' => ($sort + 1) * 10,
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->items($menuId, $id, $item['children'] ?? [], $now);
        }
    }
};
