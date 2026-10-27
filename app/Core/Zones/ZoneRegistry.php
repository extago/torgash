<?php

namespace App\Core\Zones;

use App\Core\Modules\ModuleManifest;
use App\Core\Themes\ThemeManager;

/**
 * The list of named output positions a template exposes — WooCommerce's
 * do_action('woocommerce_before_single_product') idea, made discoverable.
 *
 * The MECHANISM already existed: templates call hook_filter('<zone>', '', ...)
 * and anything can subscribe via hook_listen() with a priority. What was
 * missing is a DECLARATION, because a hook that has never rendered is invisible
 * — the only way to find our zones was to grep the Blade files. Nothing can
 * offer a merchant "put this banner here" without a list of wheres.
 *
 * So a zone is declared twice on purpose, and the two mean different things:
 *   - manifest.json / theme.json `zones`: this position EXISTS and is offered
 *     to the admin (its name, human label and group).
 *   - the hook_filter() call in the template: this is WHERE it renders.
 * Declaring without calling means an empty zone; calling without declaring
 * still works for developers but stays out of the admin list — see
 * assertDeclared(), which surfaces that as a warning in debug mode rather
 * than letting it be a silent typo.
 *
 * A theme.json zone may additionally carry `default_blocks` — content the theme
 * ships for its own position. That key is not read here (the registry only
 * cares that the position exists); torgash.blocks seeds it, see that module's
 * seed_theme_default_blocks migration.
 *
 * Only OUTPUT zones belong here. The same bus also carries data filters
 * (cart.total, catalog.product.stock_locked, core.field.rich) — subscribing a
 * content block to those would be meaningless, and listing them would offer
 * the merchant "place a banner inside the cart total".
 */
class ZoneRegistry
{
    /**
     * What kind of place a zone is — declared beside its label (`shape` in
     * manifest.json / theme.json, `wide` when absent), so a block picker can
     * offer what makes sense THERE: a product grid is for the width of the
     * page, a town switch for a line in the header. English source strings,
     * translated by core's lang file.
     *
     * - wide:   the width of the page's content (the homepage, under a
     *           category, under a product)
     * - narrow: a side column — the product's buy box, a page's sidebar, one
     *           column of the footer
     * - inline: a line inside the header, one short control beside another
     * - drawer: the phone's menu, a stack of rows
     */
    public const SHAPES = [
        'wide' => 'Full width',
        'narrow' => 'Narrow column',
        'inline' => 'In a line',
        'drawer' => 'Phone menu',
    ];

    /** @var array<string, array{name: string, label: array<string,string>, group: string, source: string, shape: string}> */
    protected array $zones = [];

    protected bool $loaded = false;

    public function __construct(protected ThemeManager $themes) {}

    /**
     * @param  array<string,string>|string  $label  English (translated through lang files), or locale => text
     */
    /** The zones as they stood when a persistent worker finished booting. */
    protected ?array $frozen = null;

    /**
     * Remember the zones as they stand now, and put them back at the start of
     * every request (App\Core\Support\WorkerMode): a storefront on another
     * theme ADDS that theme's zones, and a worker serving the next storefront
     * would still list them.
     */
    public function freeze(): void
    {
        $this->frozen = [$this->zones, $this->loaded];
    }

    public function thaw(): void
    {
        if ($this->frozen !== null) {
            [$this->zones, $this->loaded] = $this->frozen;
        }
    }

    public function register(string $name, array|string $label, string $group = 'general', string $source = 'core', string $shape = 'wide'): void
    {
        $this->zones[$name] = [
            'name' => $name,
            'label' => $label,
            'group' => $group,
            'source' => $source,
            'shape' => isset(self::SHAPES[$shape]) ? $shape : 'wide',
        ];
    }

    /** Zones a module's manifest declares. */
    public function registerFromModule(ModuleManifest $manifest, array $zones): void
    {
        foreach ($zones as $zone) {
            if (empty($zone['name'])) {
                continue;
            }
            $this->register(
                $zone['name'],
                $zone['label'] ?? [],
                $zone['group'] ?? $manifest->id,
                $manifest->id,
                (string) ($zone['shape'] ?? 'wide'),
            );
        }
    }

    /**
     * Zones the active theme (and its parents) declare in theme.json. A theme
     * that adds its own markup can expose its own positions without touching
     * a module — the whole point of a custom theme being able to define zones.
     */
    public function registerFromThemes(): void
    {
        // Parents first, so a child theme's declaration wins on name collision.
        foreach (array_reverse($this->themes->chain()) as $theme) {
            foreach ($this->themes->meta($theme)['zones'] ?? [] as $zone) {
                if (empty($zone['name'])) {
                    continue;
                }
                $this->register(
                    $zone['name'],
                    $zone['label'] ?? [],
                    $zone['group'] ?? 'theme',
                    'theme:'.$theme,
                    (string) ($zone['shape'] ?? 'wide'),
                );
            }
        }

        $this->loaded = true;
    }

    /** @return array<string, array{name: string, label: array<string,string>, group: string, source: string}> */
    public function all(): array
    {
        return $this->zones;
    }

    /** Zones grouped for the admin picker, group => list of zones. */
    public function grouped(): array
    {
        $grouped = [];
        foreach ($this->zones as $zone) {
            $grouped[$zone['group']][] = $zone;
        }
        ksort($grouped);

        return $grouped;
    }

    public function has(string $name): bool
    {
        return isset($this->zones[$name]);
    }

    public function find(string $name): ?array
    {
        return $this->zones[$name] ?? null;
    }

    public function label(string $name, ?string $locale = null): string
    {
        $zone = $this->find($name);
        if (! $zone) {
            return $name;
        }

        $locale ??= app()->getLocale();

        return label_text($zone['label'] ?? null, $locale, $name);
    }

    /**
     * Called by the zone() helper. A template rendering a zone nobody declared
     * is almost always a typo in the zone name — which would otherwise fail
     * completely silently, since an unsubscribed hook just returns its seed.
     * Loud in debug, ignored in production.
     */
    public function assertDeclared(string $name): void
    {
        if (! $this->loaded || $this->has($name) || ! config('app.debug')) {
            return;
        }

        logger()->warning("Template rendered undeclared zone [{$name}] — add it to the module's manifest.json or the theme's theme.json to make it available in the admin.");
    }
}
