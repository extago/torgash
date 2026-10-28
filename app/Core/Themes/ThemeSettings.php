<?php

namespace App\Core\Themes;

use App\Core\Settings\SettingsManager;
use App\Core\Support\Colour;
use App\Models\Storefront;
use Illuminate\Support\Facades\File;

/**
 * A theme's own settings (PLAN §37) — what `theme.json` declares under
 * `settings`, and the values ONE storefront chose for them.
 *
 * - The SCHEMA comes from the whole chain, parent first, so a child theme
 *   sees its parent's fields and may redeclare one by key.
 * - The VALUES live in the ordinary `settings` table under the module id
 *   `theme.<dir>`, scoped to the storefront (SettingsManager already knows
 *   per-storefront overrides), defaults from the schema.
 * - A DRAFT — the configurator's unsaved values — is kept in the admin's
 *   session and answers instead of the saved ones only for a page the
 *   browser marked as loaded INTO A FRAME (`Sec-Fetch-Dest: iframe`), for
 *   somebody allowed to manage themes. That is what lets the preview follow
 *   links inside the frame while the same storefront in the next tab, and
 *   every customer, keeps seeing what was saved.
 *
 * How a value reaches the page is declared in the schema too: `css` names a
 * custom property (optionally with `unit`, or `css_map` from option to CSS
 * value), printed by <x-theme.style />; anything else a template asks
 * through theme_setting().
 *
 * A per-request singleton: the head, the layout and every partial ask it.
 */
class ThemeSettings
{
    public const DRAFT = 'theme_draft';

    /** @var array<string, array<string, mixed>>|null */
    protected ?array $schema = null;

    /** @var array<string, mixed>|null */
    protected ?array $values = null;

    public function __construct(protected ThemeManager $themes, protected SettingsManager $settings) {}

    public function moduleId(?string $theme = null): string
    {
        return 'theme.'.($theme ?? $this->themes->active());
    }

    /**
     * The fields, keyed, in declaration order (a parent's first).
     *
     * @return array<string, array<string, mixed>>
     */
    public function schema(): array
    {
        if ($this->schema !== null) {
            return $this->schema;
        }

        $fields = [];

        foreach (array_reverse($this->themes->chain()) as $theme) {
            foreach ((array) ($this->themes->meta($theme)['settings'] ?? []) as $field) {
                if (empty($field['key'])) {
                    continue;
                }

                // A child may redeclare a field — most often only to change
                // its default. What the base stylesheet already draws is the
                // FIRST declaration's default, so that is what "unchanged"
                // is measured against (a child's own default must print).
                $parent = $fields[$field['key']] ?? [];
                $fields[$field['key']] = $field + $parent + [
                    'section' => 'general',
                    'theme' => $theme,
                    'base_default' => $parent['base_default'] ?? ($field['default'] ?? null),
                ];
            }
        }

        // A colour that names its dark-palette value (`dark`) gets a twin
        // field for it, `<key>__dark`: empty means "automatic" — worked out
        // from the light one (App\Core\Support\Colour::darkTwin) — and a
        // colour means exactly that colour. The configurator draws the twin
        // inside its colour's field, so sections() leaves it out.
        foreach ($fields as $key => $field) {
            if (($field['type'] ?? null) === 'color' && array_key_exists('dark', $field) && ! isset($fields[$key.self::DARK])) {
                $fields = $this->insertAfter($fields, $key, $key.self::DARK, [
                    'key' => $key.self::DARK, 'type' => 'color_dark', 'section' => $field['section'],
                    'theme' => $field['theme'], 'twin_of' => $key, 'default' => '', 'base_default' => '',
                ]);
            }
        }

        $this->settings->registerDefaults($this->moduleId(), array_map(fn ($f) => $f['default'] ?? null, $fields));

        return $this->schema = $fields;
    }

    /** @return array<string, list<array<string, mixed>>> section => fields */
    public function sections(): array
    {
        $out = [];

        foreach ($this->schema() as $field) {
            if (empty($field['twin_of'])) {
                $out[$field['section']][] = $field;
            }
        }

        return $out;
    }

    /** Suffix of a colour's dark-palette twin field. */
    public const DARK = '__dark';

    /**
     * @param  array<string, array<string, mixed>>  $fields
     * @param  array<string, mixed>  $field
     * @return array<string, array<string, mixed>>
     */
    protected function insertAfter(array $fields, string $after, string $key, array $field): array
    {
        $out = [];

        foreach ($fields as $k => $f) {
            $out[$k] = $f;
            if ($k === $after) {
                $out[$key] = $field;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> key => the value the page is drawn with */
    public function values(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $storefrontId = current_storefront()?->id;
        $values = [];

        foreach ($this->schema() as $key => $field) {
            $values[$key] = $this->stored($this->moduleId(), $key, $field, $storefrontId);
        }

        if ($storefrontId !== null && $this->previewing()) {
            $values = $this->clean((array) session(self::DRAFT.'.'.$storefrontId, [])) + $values;
        }

        return $this->values = $values;
    }

    /**
     * One field's stored value for a storefront, read through what the schema
     * says about its PAST, so a shop that chose before a field changed keeps
     * what it chose:
     * - `renamed`: {old option => new option} — a stored old answer reads as
     *   its new name (the header's "dark"/"light" became "two_rows"/"three_rows");
     * - `follows`: {key, map} — a NEW field nothing was stored for yet takes
     *   its answer from another field's stored one (the header's palette from
     *   what "light" used to mean), else its default.
     */
    public function stored(string $module, string $key, array $field, ?int $storefrontId): mixed
    {
        // The theme registers its defaults with the manager, so "stored" is
        // asked for, not inferred from the answer.
        if ($this->settings->has($module, $key, $storefrontId)) {
            return self::renamed($field, $this->settings->get($module, $key, null, $storefrontId));
        }

        $from = (string) ($field['follows']['key'] ?? '');

        if ($from !== '' && $this->settings->has($module, $from, $storefrontId)) {
            $was = $this->settings->get($module, $from, null, $storefrontId);

            if (is_scalar($was) && isset($field['follows']['map'][(string) $was])) {
                return $field['follows']['map'][(string) $was];
            }
        }

        return self::renamed($field, $this->settings->get($module, $key, $field['default'] ?? null, $storefrontId));
    }

    /** An option's current name, when the schema renamed it. */
    protected static function renamed(array $field, mixed $value): mixed
    {
        return is_scalar($value) && isset($field['renamed'][(string) $value]) ? $field['renamed'][(string) $value] : $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values()[$key] ?? $default;
    }

    /** Is this page the configurator's preview frame, showing a draft? */
    public function previewing(): bool
    {
        return $this->framed()
            && session()->has(self::DRAFT.'.'.(current_storefront()?->id ?? 0));
    }

    /**
     * May this page be in the configurator's frame — framed, for somebody
     * allowed to manage themes — draft or no draft? What only that frame
     * needs (answering as a touch screen, assets/js/preview-touch.js) is
     * printed on this, never for a customer.
     */
    public function framed(): bool
    {
        return request()->headers->get('Sec-Fetch-Dest') === 'iframe'
            && (bool) auth()->user()?->can('themes.manage');
    }

    /**
     * Only keys the schema knows, only answers a field can give — an option
     * that exists, a colour that is a colour, a switch that is a switch. The
     * configurator's form, an imported file and a preset all come through
     * here, and the values end up inside a <style> tag.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function clean(array $input): array
    {
        $out = [];

        foreach ($this->schema() as $key => $field) {
            if (! array_key_exists($key, $input)) {
                continue;
            }

            // An imported file or a preset written before an option was renamed.
            $value = self::renamed($field, $input[$key]);

            $ok = match ($field['type'] ?? 'text') {
                'segmented', 'select' => is_scalar($value) && array_key_exists((string) $value, $this->options($field)),
                'color' => is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1,
                // A background: the theme's (empty), a two-colour gradient in
                // one of three directions, or a picture WE stored.
                'background' => is_string($value) && self::validBackground($value),
                // A dark-palette twin: a colour, or empty for "automatic".
                'color_dark' => $value === '' || $value === null || (is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) === 1),
                'checkbox' => true,
                // A number within the field's `min`/`max` (a width, a corner).
                'number' => is_numeric($value)
                    && (float) $value >= (float) ($field['min'] ?? PHP_INT_MIN)
                    && (float) $value <= (float) ($field['max'] ?? PHP_INT_MAX),
                'image' => is_string($value) && ($value === '' || self::isUploadedImage($value)),
                // Free text the shop writes (its own CSS) — any string of a
                // sane length; how it is PRINTED is the style component's job.
                'code' => is_string($value) && strlen($value) <= 100_000,
                default => false,
            };

            if ($ok) {
                $out[$key] = match ($field['type'] ?? '') {
                    'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                    // Written the way the default is ("8", not "8.0" or " 8"),
                    // so an untouched value still compares equal to it.
                    'number' => (string) (0 + $value),
                    'color', 'color_dark' => strtolower((string) $value),
                    // A gradient's colours as a colour is written; a picture's
                    // path is a file name, and those mind their case.
                    'background' => str_starts_with((string) $value, 'gradient:') ? strtolower((string) $value) : (string) $value,
                    default => (string) $value,
                };
            }
        }

        return $out;
    }

    /**
     * A field's choices. Usually typed in theme.json; a field may instead say
     * `"options_from": "menus"`, and the rest is asked of whoever answers
     * `core.theme.options.menus` — a theme cannot know the shop's own rows (its
     * menus) and core must not know which module keeps them. Asked only here
     * and by the configurator, never while a storefront page is drawn: the
     * value is all a page needs.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, mixed> value => label (a string or {en, ru})
     */
    public function options(array $field): array
    {
        $options = (array) ($field['options'] ?? []);

        if (! empty($field['options_from']) && is_string($field['options_from'])) {
            $options = (array) hook_filter('core.theme.options.'.$field['options_from'], $options, ['field' => $field]);
        }

        return $options;
    }

    /** @param  array<string, mixed>  $values  already cleaned */
    public function save(Storefront $storefront, array $values): void
    {
        foreach ($this->clean($values) as $key => $value) {
            $this->settings->set($this->moduleId($storefront->theme), $key, $value, $storefront->id);
        }

        $this->forgetDraft($storefront);
    }

    /** "Default": the storefront's own values go, the theme's defaults answer. */
    public function reset(Storefront $storefront): void
    {
        foreach (array_keys($this->schema()) as $key) {
            $this->settings->forget($this->moduleId($storefront->theme), $key, $storefront->id);
        }

        $this->forgetDraft($storefront);
    }

    public function draft(Storefront $storefront, array $values): void
    {
        session([self::DRAFT.'.'.$storefront->id => $this->clean($values)]);
    }

    public function forgetDraft(Storefront $storefront): void
    {
        session()->forget(self::DRAFT.'.'.$storefront->id);
        $this->values = null;
    }

    /**
     * The custom properties the schema maps values to.
     *
     * @return array<string, string> property => CSS value
     */
    public function cssVariables(): array
    {
        $out = [];

        foreach ($this->schema() as $key => $field) {
            // A colour with a dark twin is printed per palette (palettes()).
            if (empty($field['css']) || isset($field['twin_of']) || $this->hasTwin($field)) {
                continue;
            }

            $value = $this->get($key);

            // The base default DESCRIBES what the stylesheet already draws, so
            // it prints nothing: an untouched storefront is byte-for-byte the
            // theme as written, and the dark palette keeps its own colours.
            if ((string) $value === (string) ($field['base_default'] ?? '')) {
                continue;
            }

            $css = isset($field['css_map']) ? ($field['css_map'][(string) $value] ?? null) : $value.($field['unit'] ?? '');

            if ($css === null || $css === '' || preg_match('/[;{}<>]/', (string) $css)) {
                continue;
            }

            foreach ((array) $field['css'] as $property) {
                $out[$property] = (string) $css;
            }
        }

        return $out;
    }

    /** The directions a background gradient may take, in degrees. */
    public const GRADIENT_ANGLES = ['90', '135', '180'];

    public static function validBackground(string $value): bool
    {
        return $value === ''
            || preg_match('/^gradient:#[0-9a-f]{6}:#[0-9a-f]{6}:('.implode('|', self::GRADIENT_ANGLES).')$/i', $value) === 1
            || (str_starts_with($value, 'image:') && self::isUploadedImage(substr($value, 6)));
    }

    /**
     * A `background` field's rule for its `selector` — background-IMAGE only,
     * so the zone's colour (a role, with its text) stays underneath: a
     * picture still loading, or one with transparent parts, shows the
     * colour, and the words keep the contrast they were given.
     */
    protected function backgroundRule(array $field, string $value): ?string
    {
        $selector = (string) ($field['selector'] ?? '');

        if ($selector === '' || preg_match('/[{}<>;]/', $selector) || ! self::validBackground($value) || $value === '') {
            return null;
        }

        if (str_starts_with($value, 'gradient:')) {
            [, $from, $to, $angle] = explode(':', $value);

            return "{$selector} { background-image: linear-gradient({$angle}deg, {$from}, {$to}); }";
        }

        // Shaped like ours is enough on the way out (see imageUrl()).
        $url = \Illuminate\Support\Facades\Storage::disk('public')->url(substr($value, 6));

        $url = str_replace(['"', '\\', "\n"], '', $url);

        return "{$selector} { background-image: url(\"{$url}\"); background-size: cover; background-position: center; }";
    }

    /** @param  array<string, mixed>  $field */
    protected function hasTwin(array $field): bool
    {
        return ($field['type'] ?? null) === 'color' && array_key_exists('dark', $field);
    }

    /**
     * The colours that have a dark twin, as two sets of custom properties —
     * one for the light palette, one for the dark (PLAN §52).
     *
     * Light: the chosen colour when it differs from the theme's, and the text
     * that reads on it (`on`, white or near-black by contrast). Dark: the
     * twin the shop typed, else — only when the light colour was changed —
     * the twin worked out from it (`role`: text rises, a surface sinks, a
     * brand colour stays). Nothing changed prints nothing: the stylesheet's
     * own palettes already draw the theme as written.
     *
     * @return array{light: array<string, string>, dark: array<string, string>}
     */
    public function palettes(): array
    {
        // The dark palette's TONE first (a field with `dark_surfaces`): every
        // twin below — typed, or worked out from a changed light colour — is
        // a decision about one colour and goes over it.
        $out = ['light' => [], 'dark' => $this->darkSurfaces()];

        foreach ($this->schema() as $key => $field) {
            if (empty($field['css']) || ! $this->hasTwin($field)) {
                continue;
            }

            $light = strtolower((string) $this->get($key));
            $typedDark = strtolower((string) $this->get($key.self::DARK, ''));
            $lightChanged = Colour::valid($light) && $light !== strtolower((string) ($field['base_default'] ?? ''));

            if ($lightChanged) {
                $this->paint($out['light'], $field, $light);
            }

            $dark = match (true) {
                Colour::valid($typedDark) => $typedDark,
                $lightChanged => Colour::darkTwin($light, (string) ($field['role'] ?? ''), (string) ($field['dark_background'] ?? '#1c2230')),
                default => null,
            };

            if ($dark !== null) {
                $this->paint($out['dark'], $field, $dark);
            }
        }

        return $out;
    }

    /**
     * The dark palette's surfaces as a tone (the field that declares
     * `dark_surfaces`: option => property => colour). The theme's own option
     * prints nothing, the stylesheet already draws it. An option given as a
     * colour field's KEY instead of a map ("brand": "primary") is the
     * default option's surfaces turned to that colour's hue, as faintly as
     * the default is tinted — a violet shop's dark scheme leans violet.
     *
     * @return array<string, string> property => colour
     */
    public function darkSurfaces(): array
    {
        foreach ($this->schema() as $key => $field) {
            if (! is_array($field['dark_surfaces'] ?? null)) {
                continue;
            }

            $value = (string) $this->get($key);
            $base = (array) ($field['dark_surfaces'][$field['base_default'] ?? ''] ?? []);
            $tone = $field['dark_surfaces'][$value] ?? null;

            if ($value === (string) ($field['base_default'] ?? '') || $tone === null) {
                return [];
            }

            if (is_string($tone)) {
                $hueOf = strtolower((string) $this->get($tone));
                if (! Colour::valid($hueOf)) {
                    return [];
                }
                [, $hueC, $hue] = Colour::oklch($hueOf);
                $tone = [];
                foreach ($base as $property => $colour) {
                    [$L, $C] = Colour::oklch((string) $colour);
                    // A grey base colour has no hue to lend: it stays grey.
                    $tone[$property] = Colour::fromOklch($L, min($C, $hueC), $hue);
                }
            }

            return array_filter(array_map('strtolower', (array) $tone), fn ($colour) => Colour::valid($colour));
        }

        return [];
    }

    /**
     * @param  array<string, string>  $set
     * @param  array<string, mixed>  $field
     */
    protected function paint(array &$set, array $field, string $colour): void
    {
        foreach ((array) $field['css'] as $property) {
            $set[$property] = $colour;
        }

        foreach ((array) ($field['on'] ?? []) as $property) {
            $set[$property] = Colour::ink($colour);
        }
    }

    /**
     * The rules a changed field needs besides its variable — a field may carry
     * `style`, a whole CSS rule reading that variable (headings' weight, the
     * body's size, pictures' corners). Printed ONLY when the value differs
     * from the default: such a rule is unlayered and would outrank Tailwind's
     * own utilities, so printed always it would restyle an untouched shop.
     *
     * @return list<string>
     */
    public function rules(): array
    {
        $out = [];

        foreach ($this->schema() as $key => $field) {
            if (! empty($field['style']) && (string) $this->get($key) !== (string) ($field['base_default'] ?? '')) {
                $out[] = (string) $field['style'];
            }

            if (($field['type'] ?? null) === 'background' && ($rule = $this->backgroundRule($field, (string) $this->get($key, ''))) !== null) {
                $out[] = $rule;
            }
        }

        return $out;
    }

    /**
     * The @font-face rules a chosen option needs — a field option may name
     * files under the theme's assets (`fonts`), found up the chain.
     *
     * @return list<array{family: string, url: string, range: string}>
     */
    public function fontFaces(): array
    {
        $faces = [];

        foreach ($this->schema() as $key => $field) {
            foreach ((array) ($field['fonts'][(string) $this->get($key)] ?? []) as $font) {
                if (($url = $this->assetUrl((string) ($font['file'] ?? ''))) !== null) {
                    $faces[] = ['family' => (string) $font['family'], 'url' => $url, 'range' => (string) ($font['range'] ?? '')];
                }
            }
        }

        return $faces;
    }

    /** A file under some theme's assets/, nearest in the chain first. */
    public function assetUrl(string $path): ?string
    {
        if ($path === '' || str_contains($path, '..')) {
            return null;
        }

        foreach ($this->themes->chain() as $theme) {
            if (File::exists(config('torgash.themes_path').DIRECTORY_SEPARATOR.$theme.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.$path)) {
                return theme_asset($theme, $path);
            }
        }

        return null;
    }

    /** Where an `image` field's uploads live on the public disk, per storefront. */
    public const IMAGE_DIR = 'theme-images';

    /**
     * An `image` field's picture: what the shop uploaded, else the theme's own
     * file named by the field's `default` (a path under the theme's assets/),
     * else null — the template then draws what it draws without one.
     */
    public function imageUrl(string $key): ?string
    {
        $field = $this->schema()[$key] ?? null;

        if (! $field || ($field['type'] ?? null) !== 'image') {
            return null;
        }

        $value = (string) $this->get($key, '');

        // Shaped like ours is enough on the way OUT: the file's presence was
        // checked when it was saved, and asking a bucket on every page view
        // is a request to another server per request to ours.
        if ($value !== '' && self::isUploadedImage($value, checkExists: false)) {
            return \Illuminate\Support\Facades\Storage::disk('public')->url($value);
        }

        $default = (string) ($field['default'] ?? '');

        return $default === '' ? null : $this->assetUrl($default);
    }

    /**
     * A value an `image` field may hold: a file WE wrote, under our directory,
     * named the way we name them, and still there. Anything else a request
     * carries (a path of its own, `../`) is refused rather than printed into
     * an <img>.
     */
    public static function isUploadedImage(string $path, bool $checkExists = true): bool
    {
        return preg_match('#^'.self::IMAGE_DIR.'/\d+/[a-z0-9_]+-[A-Za-z0-9]{16}\.(png|jpe?g|webp|gif)$#', $path) === 1
            && (! $checkExists || \Illuminate\Support\Facades\Storage::disk('public')->exists($path));
    }

    /** Forget what this request knew — the active theme changed under it. */
    public function flush(): void
    {
        $this->schema = null;
        $this->values = null;
    }
}
