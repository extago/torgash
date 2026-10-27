<?php

namespace Torgash\Menus;

/**
 * The shop's live SHORTCUTS a menu item can be (PLAN §39.3): home, "open the
 * catalogue", the basket with its count, the wishlist, the comparison, the
 * profile. Not a registry of this module's own — they are the entries modules
 * already hand the phone's tab bar through `storefront.tabbar`
 * (`key`, `label`, `icon`, `url` or `drawer`, `badge`, `active`), so one
 * registration serves the automatic bar, a menu's tab bar, the drawer and the
 * header alike. An entry with `'auto' => false` is offered here but kept out
 * of the AUTOMATIC bar, which has five places and fills them by `order` —
 * that is how the comparison joins without pushing the basket off it.
 *
 * The first three are the theme's own tabs, stated here as well because a
 * menu has to be able to name them with the theme's partial not drawn.
 */
class Shortcuts
{
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $shortcuts = null;

    /** @return list<array<string, mixed>> what every storefront has, before any module */
    public static function base(): array
    {
        return [
            ['key' => 'home', 'label' => __('Home'), 'icon' => 'house', 'url' => route('catalog.home'), 'order' => 10, 'active' => request()->routeIs('*catalog.home')],
            ['key' => 'catalog', 'label' => __('Catalog'), 'icon' => 'layout-grid', 'drawer' => true, 'url' => route('catalog.index'), 'order' => 20, 'active' => request()->routeIs('*catalog.index', '*catalog.category')],
            ['key' => 'account', 'label' => __('Profile'), 'icon' => 'user-round', 'url' => auth()->check() ? route('account.dashboard') : route('account.login'), 'order' => 80, 'active' => request()->routeIs('*account.*')],
        ];
    }

    /** @return array<string, array<string, mixed>> key => entry */
    public function all(): array
    {
        return $this->shortcuts ??= collect((array) hook_filter('storefront.tabbar', self::base(), ['storefront' => current_storefront()]))
            ->filter(fn ($s) => is_array($s) && ! empty($s['key']))
            ->keyBy('key')
            ->all();
    }

    public function find(?string $key): ?array
    {
        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    /** @return array<string, string> key => label, for the admin's select */
    public function options(): array
    {
        return array_map(fn (array $s) => (string) ($s['label'] ?? $s['key']), $this->all());
    }

    public function flush(): void
    {
        $this->shortcuts = null;
    }
}
