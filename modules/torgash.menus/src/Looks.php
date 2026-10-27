<?php

namespace Torgash\Menus;

use Illuminate\Support\Facades\View;

/**
 * How a menu is DRAWN (PLAN §39.5): a registry of looks, each a Blade view that
 * receives `$menuNodes` (list of Node) and the block's settings. Answered
 * lazily through `menus.looks`; a theme redraws one by overriding its view at
 * `themes/<theme>/views/modules/torgash.menus/looks/<key>.blade.php`, and adds
 * one of its own by answering the hook.
 *
 *     'key' => ['label' => 'A row of links', 'view' => 'torgash.menus::looks.key', 'depth' => 2]
 *
 * `depth` is the most levels the look can draw; the block cannot ask for more.
 */
class Looks
{
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $looks = null;

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return $this->looks ??= collect((array) hook_filter('menus.looks', [
            'inline' => [
                'label' => 'A row of links (the header strip)',
                'view' => 'torgash.menus::looks.inline',
                'depth' => 2,
            ],
            'dropdown' => [
                'label' => 'One button with a drop-down list',
                'view' => 'torgash.menus::looks.dropdown',
                'depth' => 2,
            ],
            'list' => [
                'label' => 'A list (a footer column, a sidebar)',
                'view' => 'torgash.menus::looks.list',
                'depth' => 3,
            ],
            'drawer' => [
                'label' => 'The phone\'s drawer: levels slide in as panels',
                'view' => 'torgash.menus::looks.drawer',
                'depth' => 3,
            ],
            'rows' => [
                'label' => 'Rows with icons and a small line (the drawer)',
                'view' => 'torgash.menus::looks.rows',
                'depth' => 1,
            ],
            'columns' => [
                'label' => 'Columns — one per top item',
                'view' => 'torgash.menus::looks.columns',
                'depth' => 3,
            ],
        ]))->filter(fn ($look) => is_array($look) && ! empty($look['view']))->all();
    }

    public function find(?string $key): ?array
    {
        $look = $key === null ? null : ($this->all()[$key] ?? null);

        return $look && View::exists($look['view']) ? $look : null;
    }

    /** @return list<array{value: string, label: array<string, string>}> the block form's select */
    public function blockOptions(): array
    {
        return collect($this->all())
            ->map(fn (array $look, string $key) => ['value' => $key, 'label' => $look['label']])
            ->values()
            ->all();
    }
}
