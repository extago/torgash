<?php

namespace Torgash\Menus\Demo;

use App\Core\Demo\DemoPack;
use Torgash\Menus\Models\Menu;
use Torgash\Menus\Models\MenuItem;

/**
 * Its part of the demo shop (PLAN §55.3): links to the demo's pages in the
 * menus the theme already made — the footer's empty "About us" and "Legal
 * information" columns, the customers' column and the top strip.
 *
 * Read from `database/demo/menus/items.json`. A page is named by its address
 * (`about`) and found through the `menus.pages` registry, the one a menu item
 * is picked from, so this module never reads the pages module's table — and
 * a page that is not there (no pages module, or the shop's own page took the
 * address) is simply not linked. A heading the shop renamed or removed puts
 * the links at the top of that menu; a link the menu already has is skipped.
 */
class MenusDemo
{
    public function install(DemoPack $pack): void
    {
        $targets = $this->pageTargets();
        $made = 0;

        foreach ($pack->json('menus/items') as $row) {
            $menu = Menu::where('code', (string) ($row['menu'] ?? ''))->first();

            if (! $menu) {
                continue;
            }

            $parent = isset($row['under'])
                ? MenuItem::where('menu_id', $menu->id)->whereNull('parent_id')->where('name->en', $row['under'])->first()
                : null;
            $sort = (int) MenuItem::where('menu_id', $menu->id)->where('parent_id', $parent?->id)->max('sort');

            foreach ((array) ($row['pages'] ?? []) as $slug) {
                $target = $targets[$slug] ?? null;

                if (! $target || MenuItem::where('menu_id', $menu->id)->where('kind', 'page')->where('target', $target)->exists()) {
                    continue;
                }

                MenuItem::create([
                    'menu_id' => $menu->id,
                    'parent_id' => $parent?->id,
                    'kind' => 'page',
                    'target' => $target,
                    'name' => [],
                    'show_on' => 'all',
                    'sort' => $sort += 10,
                    'enabled' => true,
                ]);
                $made++;
            }
        }

        $pack->say($made.' menu links.');
    }

    /**
     * The registry's pages by their address and by its last part: `about`
     * => `page:12`.
     *
     * @return array<string, string>
     */
    protected function pageTargets(): array
    {
        $targets = [];
        $exact = [];

        foreach ((array) hook_filter('menus.pages', []) as $key => $page) {
            if (! str_starts_with((string) $key, 'page:') || ! is_callable($page['url'] ?? null)) {
                continue;
            }

            $path = trim((string) parse_url((string) ($page['url'])(), PHP_URL_PATH), '/');
            $exact[$path] = (string) $key;
            $targets[basename($path)] ??= (string) $key;
        }

        // A page at the very address (`/about`) before one deeper down (`/info/about`).
        return $exact + $targets;
    }
}
