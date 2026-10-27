<?php

namespace App\Core\Themes;

use App\Core\Settings\SettingsManager;
use App\Core\Support\Colour;
use App\Models\Storefront;

/**
 * The shop's own colours, for screens that are NOT the storefront (PLAN §52,
 * step 6) — the admin's label and promotion forms draw a badge "in the
 * theme's accent", and the admin's stylesheet knows only the theme's
 * defaults: the shop that made its accent green saw yellow badges there.
 *
 * Read from the storefront's theme chain and its saved values without making
 * that theme the active one (which would re-lay the admin's view paths): a
 * role is found by the custom property a field writes (`--color-accent`).
 * Only light values — the admin is not dark-themed by the shop.
 *
 * A per-request memo, never a static.
 */
class ShopPalette
{
    /** The roles a label or a sale may wear, by the property that draws them. */
    public const ROLES = [
        'primary' => '--color-primary',
        'secondary' => '--color-secondary',
        'accent' => '--color-accent',
        'neutral' => '--color-neutral',
        'info' => '--color-info',
        'success' => '--color-success',
        'warning' => '--color-warning',
        'error' => '--color-error',
    ];

    /** @var array<int, array{colours: array<string, string>, vars: array<string, string>}> by storefront id */
    protected array $memo = [];

    public function __construct(protected ThemeManager $themes, protected SettingsManager $settings) {}

    /** @return array<string, string> role => #hex, for the roles the theme lets the shop colour */
    public function colours(?Storefront $storefront = null): array
    {
        return $this->read($storefront)['colours'];
    }

    /**
     * The palette as custom properties for an element's `style`, so daisyUI's
     * own `badge-accent` inside it draws the shop's accent — exactly what the
     * storefront prints (ThemeSettings): a role only when the shop changed it,
     * with its text by contrast where the field has `on`, and a text the shop
     * chose (a `css_map` field writing `<role>-content`, the buttons' text).
     */
    public function styleVars(?Storefront $storefront = null): string
    {
        return implode(';', array_map(fn ($k, $v) => $k.':'.$v, array_keys($vars = $this->read($storefront)['vars']), $vars));
    }

    /** @return array{colours: array<string, string>, vars: array<string, string>} */
    protected function read(?Storefront $storefront): array
    {
        $storefront ??= Storefront::query()->where('is_default', true)->first() ?? Storefront::query()->first();

        if (! $storefront) {
            return ['colours' => [], 'vars' => []];
        }

        if (isset($this->memo[$storefront->id])) {
            return $this->memo[$storefront->id];
        }

        $theme = $storefront->theme ?: (string) config('torgash.default_theme', 'default');
        $module = 'theme.'.$theme;
        $fields = [];

        // Parent first, so a child's redeclared default wins; the FIRST
        // declaration's default is what the stylesheet draws (base_default).
        foreach (array_reverse($this->themes->chain($theme)) as $dir) {
            foreach ((array) ($this->themes->meta($dir)['settings'] ?? []) as $field) {
                if (! empty($field['key'])) {
                    $parent = $fields[$field['key']] ?? [];
                    $fields[$field['key']] = $field + $parent + ['base_default' => $parent['base_default'] ?? ($field['default'] ?? null)];
                }
            }
        }

        $colours = [];
        $vars = [];
        $value = fn (array $field) => $this->settings->get($module, $field['key'], $field['default'] ?? null, $storefront->id);

        foreach (self::ROLES as $role => $property) {
            foreach ($fields as $field) {
                if (($field['type'] ?? null) !== 'color' || ! in_array($property, (array) ($field['css'] ?? []), true)) {
                    continue;
                }

                $hex = strtolower((string) $value($field));
                if (Colour::valid($hex)) {
                    $colours[$role] = $hex;
                    if ($hex !== strtolower((string) $field['base_default'])) {
                        $vars[$property] = $hex;
                        foreach ((array) ($field['on'] ?? []) as $on) {
                            $vars[$on] = Colour::ink($hex);
                        }
                    }
                }
                break;
            }
        }

        // A text the shop picked from a list (the buttons' white or black).
        foreach ($fields as $field) {
            if (isset($field['css_map']) && ! empty($field['css'])) {
                $chosen = (string) $value($field);
                if ($chosen !== (string) ($field['base_default'] ?? '') && isset($field['css_map'][$chosen])) {
                    foreach ((array) $field['css'] as $property) {
                        if (str_ends_with($property, '-content') && in_array(substr($property, 0, -8), self::ROLES, true)) {
                            $vars[$property] = (string) $field['css_map'][$chosen];
                        }
                    }
                }
            }
        }

        return $this->memo[$storefront->id] = ['colours' => $colours, 'vars' => $vars];
    }
}
