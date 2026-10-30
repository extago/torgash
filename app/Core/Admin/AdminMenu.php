<?php

namespace App\Core\Admin;

use App\Core\Modules\ModuleManager;

/**
 * The admin sidebar as DATA: sections, core's own screens, and every enabled
 * module's `admin_menu` items, each placed in a section by its `group`.
 *
 * One list for everything that shows a screen — the sidebar draws it, and
 * `AdminSearch` reads core's screens from it — so a screen added here is in
 * both, and a section is a word a module names rather than markup it cannot
 * touch.
 *
 * The sections are what a shop owner looks for, not what the code is split
 * into: "where are the delivery zones" is Logistics whichever module holds
 * them. A module may add a section of its own through `core.admin.sections`
 * (key => label map + icon + position); an item naming a group nobody
 * declared lands in "Other" at the end rather than vanishing. Two old group
 * names are read for ever — `store` (every item written before sections
 * existed) and `appearance`.
 */
class AdminMenu
{
    /** English source labels, translated where they are drawn. */
    public const SECTIONS = [
        'catalog' => ['label' => 'Catalog', 'icon' => 'package', 'position' => 10],
        'sales' => ['label' => 'Sales', 'icon' => 'shopping-cart', 'position' => 20],
        'marketing' => ['label' => 'Marketing', 'icon' => 'megaphone', 'position' => 30],
        'seo' => ['label' => 'SEO', 'icon' => 'search-check', 'position' => 35],
        'customers' => ['label' => 'Customers', 'icon' => 'users', 'position' => 40],
        'logistics' => ['label' => 'Logistics', 'icon' => 'truck', 'position' => 50],
        'content' => ['label' => 'Appearance', 'icon' => 'palette', 'position' => 60],
        'data' => ['label' => 'Data exchange', 'icon' => 'arrow-left-right', 'position' => 70],
        'security' => ['label' => 'Security', 'icon' => 'shield', 'position' => 80],
        'system' => ['label' => 'System', 'icon' => 'settings', 'position' => 90],
    ];

    /** Group names a manifest may still carry from before sections existed. */
    public const ALIASES = ['store' => 'sales', 'appearance' => 'content', 'shop' => 'catalog'];

    /**
     * This person's starred screens (`admin_preferences`, a list of item
     * paths) and which view the sidebar shows, 'favourites' or all (null).
     */
    public const FAVOURITES = 'favourites';

    public const VIEW = 'sidebar_view';

    public function __construct(protected ModuleManager $modules) {}

    /**
     * Core's own screens, in the shape a manifest item has — label as an
     * English source string rather than a locale map, since core's words live
     * in its lang files.
     *
     * @return list<array<string, mixed>>
     */
    public function coreItems(): array
    {
        return [
            ['label' => 'Customers', 'url' => route('admin.customers.index'), 'icon' => 'user', 'group' => 'customers', 'position' => 10, 'can' => 'customers.manage'],
            ['label' => 'Customer groups', 'url' => route('admin.customer-groups.index'), 'icon' => 'tag', 'group' => 'customers', 'position' => 20, 'can' => 'customers.manage'],
            ['label' => 'Verification codes', 'url' => route('admin.verification-codes'), 'icon' => 'key-round', 'group' => 'customers', 'position' => 30, 'can' => 'customers.manage',
                'keywords' => ['en' => 'otp one-time password reset sms code log', 'ru' => 'одноразовый код сброс пароля смс журнал отправок']],
            ['label' => 'Administrators', 'url' => route('admin.administrators.index'), 'icon' => 'user-cog', 'group' => 'security', 'position' => 80, 'can' => 'staff.manage'],
            ['label' => 'Permission groups', 'url' => route('admin.permission-groups.index'), 'icon' => 'shield-check', 'group' => 'security', 'position' => 81, 'can' => 'staff.manage'],
            ['label' => 'Settings', 'url' => route('admin.settings'), 'icon' => 'sliders-horizontal', 'group' => 'system', 'position' => 1, 'can' => 'settings.manage'],
            ['label' => 'Modules', 'url' => route('admin.modules'), 'icon' => 'layout-grid', 'group' => 'system', 'position' => 2, 'can' => 'modules.manage'],
            ['label' => 'Storefronts', 'url' => route('admin.storefronts.index'), 'icon' => 'store', 'group' => 'system', 'position' => 10, 'can' => 'storefronts.manage'],
            ['label' => 'Themes', 'url' => route('admin.themes.index'), 'icon' => 'swatch-book', 'group' => 'content', 'position' => 1, 'can' => 'themes.manage'],
            ['label' => 'Languages', 'url' => route('admin.languages.index'), 'icon' => 'globe', 'group' => 'system', 'position' => 11, 'can' => 'languages.manage'],
            ['label' => 'Currencies', 'url' => route('admin.currencies.index'), 'icon' => 'credit-card', 'group' => 'system', 'position' => 12, 'can' => 'currencies.manage'],
            ['label' => 'File storage', 'url' => route('admin.storage'), 'icon' => 'hard-drive', 'group' => 'system', 'position' => 20, 'can' => 'settings.manage'],
            ['label' => 'Letters', 'url' => route('admin.letters.index'), 'icon' => 'mail', 'group' => 'system', 'position' => 15, 'can' => 'mail.manage',
                'keywords' => ['en' => 'email templates notifications mail texts', 'ru' => 'шаблоны писем уведомления почта тексты письма']],
            ['label' => 'Cache and sessions', 'url' => route('admin.cache'), 'icon' => 'database-zap', 'group' => 'system', 'position' => 21, 'can' => 'cache.manage',
                'keywords' => ['en' => 'performance speed up redis memory clear cache', 'ru' => 'производительность ускорить редис память очистить кэш кеш сессии']],
            ['label' => 'Redirects', 'url' => route('admin.redirects.index'), 'icon' => 'signpost', 'group' => 'seo', 'position' => 30, 'can' => 'redirects.manage'],
            ['label' => 'System', 'url' => route('admin.system'), 'icon' => 'server', 'group' => 'system', 'position' => 25, 'can' => 'system.manage',
                'keywords' => ['en' => 'backup restore version php postgres disk database size update dependencies', 'ru' => 'резервная копия бэкап восстановить версия php postgres диск размер базы обновление зависимости система']],
            ['label' => 'Background tasks', 'url' => route('admin.tasks'), 'icon' => 'list-checks', 'group' => 'system', 'position' => 30, 'can' => 'tasks.manage'],
        ];
    }

    /**
     * What the sidebar draws: sections in order, each with its items in
     * order, empty sections left out, items the reader may not open left out.
     * Every item carries `title` (in the reader's language) and `active`.
     *
     * @return list<array{key: string, label: string, icon: string, items: list<array<string, mixed>>, active: bool}>
     */
    public function sections(): array
    {
        $declared = self::SECTIONS + $this->moduleSections();
        $items = [...array_map(fn ($i) => $i + ['core' => true], $this->coreItems()), ...$this->modules->adminMenu()];
        $user = auth()->user();

        $bySection = [];

        foreach ($items as $item) {
            if (isset($item['can']) && ! $user?->can($item['can'])) {
                continue;
            }

            $group = self::ALIASES[$item['group'] ?? ''] ?? ($item['group'] ?? 'other');
            $group = isset($declared[$group]) ? $group : 'other';

            $item['url'] = url($item['url'] ?? '#');
            $item['title'] = isset($item['core'])
                ? __($item['label'])
                : label_text($item['label'] ?? null, null, '?');
            $item['position'] = (float) ($item['position'] ?? 100);

            $bySection[$group][] = $item;
        }

        $activeUrl = $this->activeUrl(array_merge(...array_values($bySection ?: [[]])));
        $starred = (array) Preferences::get($user, self::FAVOURITES, []);
        $declared['other'] = ['label' => 'Other', 'icon' => 'blocks', 'position' => 1000];

        uasort($declared, fn ($a, $b) => ($a['position'] ?? 500) <=> ($b['position'] ?? 500));

        $sections = [];

        foreach ($declared as $key => $section) {
            if (empty($bySection[$key])) {
                continue;
            }

            $list = $bySection[$key];
            usort($list, fn ($a, $b) => $a['position'] <=> $b['position']);

            foreach ($list as &$item) {
                $item['active'] = rtrim($item['url'], '/') === $activeUrl;
                // What a favourite is remembered by: the address's path, the
                // same whatever host the admin came in on.
                $item['path'] = self::pathOf($item['url']);
                $item['favourite'] = in_array($item['path'], $starred, true);
            }
            unset($item);

            $sections[] = [
                'key' => $key,
                'label' => label_text($section['label'] ?? null, null, $key),
                'icon' => $section['icon'] ?? 'folder',
                'items' => $list,
                'active' => in_array(true, array_column($list, 'active'), true),
            ];
        }

        return $sections;
    }

    /**
     * The screens this person starred, in the sidebar's own order — the
     * sidebar's "Favourites" view, a flat list. A star on a screen that
     * is gone (a module switched off) is simply not drawn.
     *
     * @param  list<array{key: string, label: string, icon: string, items: list<array<string, mixed>>, active: bool}>  $sections
     * @return list<array<string, mixed>>
     */
    public function favourites(array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                if ($item['favourite']) {
                    $out[] = $item;
                }
            }
        }

        return $out;
    }

    /**
     * The item the current page belongs to — the one the heading's star
     * speaks for — or null on a page no item claims (the dashboard).
     *
     * @param  list<array{key: string, label: string, icon: string, items: list<array<string, mixed>>, active: bool}>  $sections
     * @return array<string, mixed>|null
     */
    public function active(array $sections): ?array
    {
        foreach ($sections as $section) {
            foreach ($section['items'] as $item) {
                if ($item['active']) {
                    return $item;
                }
            }
        }

        return null;
    }

    /** Is this path a screen of the sidebar as this person sees it? */
    public function knows(string $path): bool
    {
        foreach ($this->sections() as $section) {
            if (in_array($path, array_column($section['items'], 'path'), true)) {
                return true;
            }
        }

        return false;
    }

    public static function pathOf(string $url): string
    {
        return '/'.trim((string) parse_url($url, PHP_URL_PATH), '/');
    }

    /**
     * Which item the current page belongs to: the LONGEST item address the
     * current one starts with. A product's edit page is under "Products"
     * although its address is not the list's; and when two items share a
     * start (`/admin/shipping/methods`, `/admin/shipping/zones`) the longer
     * match is the one meant.
     *
     * @param  list<array<string, mixed>>  $items
     */
    protected function activeUrl(array $items): ?string
    {
        $current = rtrim(request()->url(), '/');
        $best = null;

        foreach ($items as $item) {
            $url = rtrim($item['url'], '/');

            if (($current === $url || str_starts_with($current, $url.'/'))
                && ($best === null || strlen($url) > strlen($best))) {
                $best = $url;
            }
        }

        return $best === null ? null : $best;
    }

    /**
     * Which section of the sidebar a module lives in — the group of its first
     * menu item, so the settings screen can file a module where the owner
     * already looks for it. A module with no menu item (an editor, a
     * measuring tool) is the system's.
     */
    public function sectionOf(\App\Core\Modules\ModuleManifest $manifest): string
    {
        $declared = self::SECTIONS + $this->moduleSections();

        // Said by the module itself — for one with no menu item to say it.
        $own = self::ALIASES[$manifest->look['section'] ?? ''] ?? ($manifest->look['section'] ?? null);
        if ($own !== null && isset($declared[$own])) {
            return $own;
        }

        foreach ($manifest->adminMenu as $item) {
            $group = self::ALIASES[$item['group'] ?? ''] ?? ($item['group'] ?? null);

            if ($group !== null) {
                return isset($declared[$group]) ? $group : 'other';
            }
        }

        return 'system';
    }

    /**
     * Every section there can be, in the sidebar's order, labels translated.
     *
     * @return array<string, array{label: string, icon: string}>
     */
    public function sectionHeadings(): array
    {
        $declared = self::SECTIONS + $this->moduleSections();
        $declared['other'] = ['label' => 'Other', 'icon' => 'blocks', 'position' => 1000];
        uasort($declared, fn ($a, $b) => ($a['position'] ?? 500) <=> ($b['position'] ?? 500));

        return array_map(fn ($section) => [
            'label' => label_text($section['label'] ?? null),
            'icon' => $section['icon'] ?? 'folder',
        ], $declared);
    }

    /** @return array<string, array<string, mixed>> */
    protected function moduleSections(): array
    {
        return array_filter(
            (array) hook_filter('core.admin.sections', [], []),
            fn ($section) => is_array($section) && isset($section['label']),
        );
    }
}
