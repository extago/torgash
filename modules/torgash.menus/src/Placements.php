<?php

namespace Torgash\Menus;

use App\Core\Settings\SettingsManager;
use App\Core\Themes\ThemeManager;
use App\Core\Zones\ZoneRegistry;
use App\Models\Storefront;
use Illuminate\Support\Facades\Route;
use Torgash\Blocks\Models\Block;

/**
 * Where each menu stands (PLAN §39.7): the `menu` blocks that chose it and
 * the theme settings (`options_from: menus` — the Catalogue button, the strip,
 * the phone's bar) that name it on some storefront. Nothing is stored — a menu
 * is placed by the block or the setting, so this is only ever READ from them,
 * and a list kept beside them would be a second answer to the same question.
 *
 * The blocks half is soft, like the block type's registration: without
 * torgash.blocks a menu simply stands in no block.
 */
class Placements
{
    /** @var array<string, list<array{kind: string, label: string, where: string, url: ?string, off: bool}>>|null */
    protected ?array $all = null;

    public function __construct(
        protected ThemeManager $themes,
        protected SettingsManager $settings,
        protected ZoneRegistry $zones,
    ) {}

    /** @return list<array{kind: string, label: string, where: string, url: ?string, off: bool}> */
    public function of(string $code): array
    {
        return $this->all()[$code] ?? [];
    }

    /** @return array<string, list<array{kind: string, label: string, where: string, url: ?string, off: bool}>> menu code => places */
    public function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }

        $out = $this->blocks();

        foreach ($this->themeSettings() as $code => $places) {
            $out[$code] = [...($out[$code] ?? []), ...$places];
        }

        return $this->all = $out;
    }

    /** @return array<string, list<array<string, mixed>>> */
    protected function blocks(): array
    {
        if (! class_exists(Block::class)) {
            return [];
        }

        $locale = app()->getLocale();
        $out = [];

        foreach (Block::query()->where('type', 'menu')->orderBy('zone')->orderBy('sort')->orderBy('id')->get() as $block) {
            $code = (string) ($block->settings['menu'] ?? '');

            if ($code === '') {
                continue;
            }

            $out[$code][] = [
                'kind' => 'block',
                'label' => trim((string) $block->title) !== '' ? (string) $block->title : __('Menu block'),
                'where' => $this->zones->has($block->zone) ? $this->zones->label($block->zone, $locale) : $block->zone,
                'url' => Route::has('admin.blocks.edit') ? route('admin.blocks.edit', $block) : null,
                'off' => ! $block->enabled,
            ];
        }

        return $out;
    }

    /**
     * Every storefront's value of every menu-valued field of ITS theme — read
     * straight from the theme's chain and the settings table, never by
     * switching the active theme, which would re-lay the whole view cascade
     * for the sake of a list.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function themeSettings(): array
    {
        $out = [];

        foreach (Storefront::query()->orderBy('id')->get() as $storefront) {
            $theme = $storefront->theme ?: (string) config('torgash.default_theme', 'default');

            foreach ($this->menuFields($theme) as $key => $field) {
                $code = (string) $this->settings->get('theme.'.$theme, $key, $field['default'] ?? null, $storefront->id);

                if ($code === '') {
                    continue;
                }

                $out[$code][] = [
                    'kind' => 'theme',
                    'label' => label_text($field['label'] ?? null, app()->getLocale(), $key),
                    'where' => __('Theme settings').' · '.$storefront->name,
                    'url' => Route::has('admin.themes.customize') ? route('admin.themes.customize', $storefront) : null,
                    'off' => ! $storefront->enabled,
                ];
            }
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> key => field, a child's redeclaration over its parent's */
    protected function menuFields(string $theme): array
    {
        $fields = [];

        foreach (array_reverse($this->themes->chain($theme)) as $link) {
            foreach ((array) ($this->themes->meta($link)['settings'] ?? []) as $field) {
                if (! empty($field['key'])) {
                    $fields[$field['key']] = $field + ($fields[$field['key']] ?? []);
                }
            }
        }

        return array_filter($fields, fn (array $f) => ($f['options_from'] ?? null) === 'menus');
    }
}
