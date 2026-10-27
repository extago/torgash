<?php

namespace Torgash\Menus;

use Closure;
use Torgash\Menus\Models\MenuItem;

/**
 * What a menu item can BE (PLAN §39.3) — a registry, answered lazily through
 * `menus.item_kinds` like every registry here, so a module adds a kind the day
 * it needs one (a published filter page, a town) without this module hearing
 * of it.
 *
 *     'kind' => [
 *         'label'   => 'Shop page',            // English; lang/ru.json translates it
 *         'icon'    => 'link',                         // the admin's list
 *         'resolve' => fn (MenuItem $item, MenuBuilder $menus): ?array,
 *         'describe'=> fn (MenuItem $item, string $locale): string,   // optional
 *     ]
 *
 * `resolve` answers what the ROW becomes on this page: `label`, `href` (null
 * = not a link), optionally `icon`, `count`, `children` (a list of Node) and
 * `replace` (draw the children IN PLACE of the item). NULL means "not drawn":
 * a page whose owner has gone, a category that is empty by its own rule. The
 * builder adds everything the row itself carries — a typed name, an icon, a
 * badge, its manual children — so a kind never has to.
 *
 * `category` is the builder's own (it needs the counts and the tree), and so
 * is registered here with a resolver that hands the row back to it.
 */
class ItemKinds
{
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $kinds = null;

    public function __construct(protected ShopPages $pages, protected Shortcuts $shortcuts) {}

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->kinds ??= collect((array) hook_filter('menus.item_kinds', $this->builtIn()))
            ->filter(fn ($kind) => is_array($kind) && ($kind['resolve'] ?? null) instanceof Closure)
            ->all();
    }

    public function find(?string $key): ?array
    {
        return $key === null ? null : ($this->all()[$key] ?? null);
    }

    /** @return array<string, string> key => label, for a select */
    public function options(?string $locale = null): array
    {
        return array_map(fn (array $kind) => ShopPages::word($kind['label'] ?? '', $locale), $this->all());
    }

    /** What the admin calls a row that has no typed name. */
    public function describe(MenuItem $item, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $kind = $this->find($item->kind);

        if (! $kind) {
            return '['.$item->kind.']';
        }

        if (($kind['describe'] ?? null) instanceof Closure) {
            return (string) ($kind['describe'])($item, $locale);
        }

        return ShopPages::word($kind['label'] ?? $item->kind, $locale);
    }

    /** @return array<string, array<string, mixed>> */
    protected function builtIn(): array
    {
        return [
            'link' => [
                'label' => 'Link',
                'icon' => 'link',
                'resolve' => fn (MenuItem $item) => $item->url
                    ? ['label' => $item->url, 'href' => $item->url]
                    : null,
                'describe' => fn (MenuItem $item) => $item->url ?: '—',
            ],
            'page' => [
                'label' => 'Shop page',
                'icon' => 'file-text',
                'resolve' => function (MenuItem $item) {
                    $href = $item->target ? $this->pages->url($item->target) : null;

                    return $href === null ? null : [
                        'label' => $this->pages->label($item->target),
                        'href' => $href,
                        'icon' => $this->pages->find($item->target)['icon'] ?? null,
                    ];
                },
                'describe' => fn (MenuItem $item, string $locale) => $item->target && $this->pages->find($item->target)
                    ? $this->pages->label($item->target, $locale)
                    : __('Page not found').' ('.$item->target.')',
            ],
            'shortcut' => [
                'label' => 'Shortcut (basket, wishlist, open the catalogue…)',
                'icon' => 'mouse-pointer-click',
                'resolve' => function (MenuItem $item) {
                    $shortcut = $this->shortcuts->find($item->target);

                    if (! $shortcut) {
                        return null;
                    }

                    $drawer = ! empty($shortcut['drawer']);

                    return [
                        'label' => (string) ($shortcut['label'] ?? $item->target),
                        'href' => $shortcut['url'] ?? ($drawer ? route('catalog.index') : null),
                        'icon' => $shortcut['icon'] ?? null,
                        'key' => (string) $shortcut['key'],
                        'action' => $drawer ? 'drawer' : null,
                        'counter' => isset($shortcut['badge']) && is_numeric($shortcut['badge']) ? (int) $shortcut['badge'] : null,
                        'active' => (bool) ($shortcut['active'] ?? false),
                    ];
                },
                'describe' => fn (MenuItem $item) => $this->shortcuts->find($item->target)['label'] ?? __('Shortcut not found').' ('.$item->target.')',
            ],
            'category' => [
                'label' => 'Category with subcategories',
                'icon' => 'folder-tree',
                'resolve' => fn (MenuItem $item, MenuBuilder $menus) => $menus->categoryNode($item),
                'describe' => fn (MenuItem $item, string $locale) => $item->sourceCategory
                    ? (string) $item->sourceCategory->t('name', $locale)
                    : __('The whole catalogue'),
            ],
            'heading' => [
                'label' => 'Heading (no link)',
                'icon' => 'heading',
                'resolve' => fn () => ['label' => '', 'href' => null],
            ],
            'divider' => [
                'label' => 'Divider',
                'icon' => 'minus',
                'resolve' => fn () => ['label' => '', 'href' => null],
                'describe' => fn () => '———',
            ],
        ];
    }

    public function flush(): void
    {
        $this->kinds = null;
    }
}
