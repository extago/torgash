<?php

namespace Torgash\Banners;

use App\Core\Themes\ThemeManager;
use App\Models\Storefront;
use Torgash\Banners\Models\Template;

/**
 * Templates as FILES (PLAN §51) — what a template starts from and can be put
 * back to. The module brings its own in `presets/`; a theme adds or replaces
 * them in `banner-templates/` (a parent's first, the child's over it). One
 * preset is up to three kinds of file beside each other:
 *
 *     hero.json          {"kind": "banner", "label": "Big slide", "fields": [...]}
 *                        ({"kind": "frame", "shape": "card", …} for a frame)
 *     hero.wide.html     a banner's HTML for one shape (wide / card / strip)
 *     slider.html        a frame's HTML
 *     hero.css           optional
 *
 * Installing the module copies every preset into the database once; from
 * then on the template is the admin's. "Restore" puts one back to its files,
 * "Add from the theme" brings the presets not there yet — the same two moves
 * as a theme's default blocks.
 */
class Presets
{
    public function __construct(protected ThemeManager $themes) {}

    /**
     * Every preset on disk for the themes the storefronts use, by key.
     *
     * @return array<string, array{key: string, kind: string, name: string, shape: ?string, fields: list<array<string, mixed>>, html: array<string, string>, css: string, preset: string}>
     */
    public function all(): array
    {
        $presets = $this->read(dirname(__DIR__).'/presets', 'module');

        foreach ($this->themeDirs() as $dir) {
            $presets = array_replace($presets, $this->read(config('torgash.themes_path').DIRECTORY_SEPARATOR.$dir.DIRECTORY_SEPARATOR.'banner-templates', 'theme:'.$dir));
        }

        return $presets;
    }

    /**
     * Presets not in the database yet become templates; one already there is
     * left alone — the admin may have changed it.
     *
     * @param  list<string>|null  $keys  only these, or all
     * @return int how many were added
     */
    public function seed(?array $keys = null): int
    {
        $existing = Template::query()->pluck('key')->all();
        $added = 0;

        foreach ($this->all() as $key => $preset) {
            if (in_array($key, $existing, true) || ($keys !== null && ! in_array($key, $keys, true))) {
                continue;
            }

            Template::query()->create($this->attributes($preset) + ['key' => $key]);
            $added++;
        }

        return $added;
    }

    /** Back to its files: HTML, CSS, fields, name. The banners keep their values. */
    public function restore(Template $template): bool
    {
        $preset = $this->all()[$template->key] ?? null;

        if ($preset === null || $preset['kind'] !== $template->kind) {
            return false;
        }

        $template->update($this->attributes($preset));

        return true;
    }

    /** Whether a template still is what its files say. */
    public function differs(Template $template): ?bool
    {
        $preset = $this->all()[$template->key] ?? null;

        if ($preset === null) {
            return null;
        }

        $html = array_filter((array) $template->html, fn ($text) => trim((string) $text) !== '');

        return $html != $preset['html']
            || trim((string) $template->css) !== $preset['css']
            || array_values((array) $template->fields) != $preset['fields']
            || $template->shape !== $preset['shape'];
    }

    /** @return array<string, mixed> */
    protected function attributes(array $preset): array
    {
        return [
            'kind' => $preset['kind'],
            'name' => $preset['name'],
            'shape' => $preset['shape'],
            'html' => $preset['html'],
            'css' => $preset['css'],
            'fields' => $preset['fields'],
            'preset' => $preset['preset'],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    protected function read(string $folder, string $source): array
    {
        $presets = [];

        foreach (glob($folder.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $key = basename($file, '.json');
            $meta = json_decode((string) file_get_contents($file), true);

            if (! is_array($meta) || ! preg_match(Fields::KEY_PATTERN, $key) || ! in_array($meta['kind'] ?? '', Template::KINDS, true)) {
                continue;
            }

            $html = [];

            if ($meta['kind'] === 'frame') {
                $html['frame'] = $this->text($folder.DIRECTORY_SEPARATOR.$key.'.html');
            } else {
                foreach (Template::SHAPES as $shape) {
                    $html[$shape] = $this->text($folder.DIRECTORY_SEPARATOR.$key.'.'.$shape.'.html');
                }
            }

            $presets[$key] = [
                'key' => $key,
                'kind' => $meta['kind'],
                'name' => (string) ($meta['label'] ?? $key),
                'shape' => $meta['kind'] === 'frame' && in_array($meta['shape'] ?? '', Template::SHAPES, true) ? $meta['shape'] : null,
                'fields' => array_values(array_filter(array_map([Fields::class, 'shape'], (array) ($meta['fields'] ?? [])))),
                'html' => array_filter($html, fn (string $text) => $text !== ''),
                'css' => $this->text($folder.DIRECTORY_SEPARATOR.$key.'.css'),
                'preset' => $source.':'.$key,
            ];
        }

        return $presets;
    }

    protected function text(string $file): string
    {
        return is_file($file) ? trim(str_replace("\r\n", "\n", (string) file_get_contents($file))) : '';
    }

    /** @return list<string> theme folders, parents before children */
    protected function themeDirs(): array
    {
        $themes = class_exists(Storefront::class) ? Storefront::query()->pluck('theme')->filter()->unique()->all() : [];
        $themes = $themes ?: [config('torgash.default_theme', 'default')];
        $dirs = [];

        foreach ($themes as $theme) {
            foreach (array_reverse($this->themes->chain($theme)) as $dir) {
                if (! in_array($dir, $dirs, true)) {
                    $dirs[] = $dir;
                }
            }
        }

        return $dirs;
    }
}
