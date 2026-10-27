<?php

namespace App\Http\Controllers\Admin;

use App\Core\Access\Handoff;
use App\Core\Themes\ThemeManager;
use App\Core\Themes\ThemeSettings;
use App\Http\Controllers\Controller;
use App\Models\Storefront;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Appearance → Themes (PLAN §37): every theme on disk and which storefronts
 * wear it, putting one on a storefront, and the configurator — its settings
 * on the left, the storefront itself in a frame on the right, redrawn with
 * the values not yet saved.
 *
 * The configurator works on ONE storefront's theme. Its schema is that
 * theme's chain, so the storefront's theme is made the active one for this
 * request first — the admin itself does not draw with it.
 */
class ThemesController extends Controller
{
    public function index(ThemeManager $themes, ThemeSettings $settings)
    {
        $storefronts = Storefront::orderBy('id')->get();
        $cards = [];

        foreach ($themes->available() as $dir => $name) {
            $meta = $themes->meta($dir);

            // A theme's description is translated by ITS lang file, and only
            // the storefront's own chain is loaded — this screen shows them all.
            $lang = config('torgash.themes_path').DIRECTORY_SEPARATOR.$dir.DIRECTORY_SEPARATOR.'lang';
            if (is_dir($lang)) {
                app('translator')->addJsonPath($lang);
            }
            $cards[] = [
                'dir' => $dir,
                'name' => $name,
                'meta' => $meta,
                'preview' => $this->previewImage($dir, $meta),
                'parent' => $meta['parent'] ?? null,
                // A theme with no settings anywhere in its chain has nothing
                // for the configurator to show.
                'configurable' => collect($themes->chain($dir))->contains(fn ($t) => ! empty($themes->meta($t)['settings'])),
                'storefronts' => $storefronts->where('theme', $dir)->values(),
            ];
        }

        return view('admin.themes.index', ['cards' => $cards, 'storefronts' => $storefronts]);
    }

    public function apply(Request $request, ThemeManager $themes)
    {
        $data = $request->validate([
            'storefront' => ['required', 'integer', 'exists:storefronts,id'],
            'theme' => ['required', Rule::in(array_keys($themes->available()))],
        ]);

        $storefront = Storefront::findOrFail($data['storefront']);
        $storefront->update(['theme' => $data['theme']]);

        return redirect()->route('admin.themes.index')->with('status', __('":storefront" now wears :theme.', [
            'storefront' => $storefront->name,
            'theme' => $themes->available()[$data['theme']],
        ]));
    }

    public function customize(Request $request, Storefront $storefront, ThemeManager $themes)
    {
        // The preview frame and its draft live in THIS address's session, so
        // a storefront with an address of its own is configured there — the
        // themes screen sends the person over with a sign-in ticket (open()).
        if (! Handoff::isHome($request, $storefront)) {
            return redirect()->route('admin.themes.index')
                ->with('status', __('":storefront" is configured on its own address — use the button on its theme.', ['storefront' => $storefront->name]));
        }

        $settings = $this->settingsFor($storefront, $themes);
        $saved = $this->saved($storefront, $settings);

        // Opening the configurator starts from what is SAVED: a draft left by
        // walking away last time would put in the frame what the form below
        // no longer shows.
        $settings->forgetDraft($storefront);

        return view('admin.themes.customize', [
            'storefront' => $storefront,
            'themeName' => $themes->available()[$storefront->theme] ?? $storefront->theme,
            'sections' => $settings->sections(),
            'sectionLabels' => $this->sectionLabels($themes),
            'values' => $saved,
            'presets' => $this->presets($themes),
            'styles' => $this->styles($settings),
            'colourSchemes' => $this->colourSchemes($themes, $settings),
            'previewUrl' => $this->storefrontUrl($storefront),
            'previewPages' => $this->previewPages($storefront),
            'sectionMeta' => $this->sectionMeta($themes),
        ]);
    }

    /**
     * The configurator of a storefront with an address of its own, opened
     * THERE: the person is signed in on that address by a one-use ticket
     * (App\Core\Access\Handoff). A POST, so no prefetcher spends it.
     */
    public function open(Request $request, Storefront $storefront, Handoff $handoff)
    {
        $path = route('admin.themes.customize', $storefront, false);

        if (Handoff::isHome($request, $storefront)) {
            return redirect($path);
        }

        $url = $handoff->issue($request->user(), $storefront, $path);

        return $url !== null ? redirect()->away($url) : redirect()->route('admin.themes.index');
    }

    /** The configurator's unsaved values, for the frame to draw with (204). */
    public function preview(Request $request, Storefront $storefront, ThemeManager $themes)
    {
        $this->settingsFor($storefront, $themes)->draft($storefront, (array) $request->input('values', []));

        return response()->noContent();
    }

    /**
     * An `image` field's upload (the no-photo picture, later a logo): stored at
     * once and answered with its path, which the configurator puts in the
     * field and sends as an ordinary draft value — so the preview shows it
     * before anything is saved, and a file never rides the draft request.
     * Raster images only: an SVG served from the shop's own origin is a page
     * that can run script.
     */
    public function image(Request $request, Storefront $storefront, ThemeManager $themes)
    {
        $settings = $this->settingsFor($storefront, $themes);
        $data = $request->validate([
            'key' => ['required', 'string'],
            'file' => ['required', 'file', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:2048'],
        ]);

        abort_unless(in_array($settings->schema()[$data['key']]['type'] ?? null, ['image', 'background'], true), 422);

        $file = $request->file('file');
        $path = $file->storeAs(
            ThemeSettings::IMAGE_DIR.'/'.$storefront->id,
            $data['key'].'-'.\Illuminate\Support\Str::random(16).'.'.strtolower($file->extension()),
            'public',
        );

        return response()->json(['path' => $path, 'url' => \Illuminate\Support\Facades\Storage::disk('public')->url($path)]);
    }

    public function save(Request $request, Storefront $storefront, ThemeManager $themes)
    {
        $this->settingsFor($storefront, $themes)->save($storefront, (array) $request->input('values', []));

        // The configurator saves in place, so the frame stays on the page it
        // was showing; the plain form (no script) still lands back here.
        if ($request->expectsJson()) {
            return response()->json(['message' => __('Saved. Customers see it now.')]);
        }

        return redirect()->route('admin.themes.customize', $storefront)->with('status', __('Saved. Customers see it now.'));
    }

    public function reset(Storefront $storefront, ThemeManager $themes)
    {
        $this->settingsFor($storefront, $themes)->reset($storefront);

        return redirect()->route('admin.themes.customize', $storefront)->with('status', __('Back to the theme\'s own defaults.'));
    }

    /**
     * A ready-made configuration: the preset's values over the theme's
     * defaults (whatever the storefront had set goes — a preset is a whole
     * look), and, when asked, its blocks laid out in their zones — announced
     * for whoever manages blocks, since core does not.
     */
    public function preset(Request $request, Storefront $storefront, ThemeManager $themes)
    {
        $settings = $this->settingsFor($storefront, $themes);
        $preset = collect($this->presets($themes))->firstWhere('key', (string) $request->input('preset'));
        abort_unless($preset, 404);

        $settings->reset($storefront);
        $settings->save($storefront, (array) ($preset['settings'] ?? []));

        if ($request->boolean('with_blocks') && ! empty($preset['blocks'])) {
            hook_action('core.theme.preset_blocks', ['zones' => (array) $preset['blocks'], 'storefront' => $storefront]);
        }

        return redirect()->route('admin.themes.customize', $storefront)->with('status', __('":preset" applied.', [
            'preset' => $this->label($preset['name'] ?? $preset['key']),
        ]));
    }

    /** The shop's own styles are kept with the theme, for every storefront. */
    public const STYLES = '_styles';

    public const MAX_STYLES = 30;

    /**
     * "Save as my style": the look in the configurator now — the form's
     * values, saved or not — kept under a name the shop gives it, beside the
     * theme's ready-made configurations. Shop-wide (every storefront wearing
     * this theme can apply it), cleaned like any value on the way in.
     */
    public function storeStyle(Request $request, Storefront $storefront, ThemeManager $themes)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $settings = $this->settingsFor($storefront, $themes);
        $values = $settings->clean((array) $request->input('values', []));
        abort_if($values === [], 422);

        $styles = $this->styles($settings);
        abort_if(count($styles) >= self::MAX_STYLES, 422, __('Up to :count styles — delete one first.', ['count' => self::MAX_STYLES]));

        $styles[] = ['key' => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(10)), 'name' => trim($data['name']), 'values' => $values, 'created_at' => now()->toIso8601String()];
        $this->putStyles($settings, $styles);

        return redirect()->route('admin.themes.customize', [$storefront, 'section' => 'presets'])->with('status', __('Style ":name" saved.', ['name' => trim($data['name'])]));
    }

    /** A style is applied the way a preset is: a whole look over the theme's defaults. */
    public function applyStyle(Storefront $storefront, string $style, ThemeManager $themes)
    {
        $settings = $this->settingsFor($storefront, $themes);
        $found = collect($this->styles($settings))->firstWhere('key', $style);
        abort_unless($found, 404);

        $settings->reset($storefront);
        $settings->save($storefront, (array) $found['values']);

        return redirect()->route('admin.themes.customize', $storefront)->with('status', __('":preset" applied.', ['preset' => $found['name']]));
    }

    public function destroyStyle(Storefront $storefront, string $style, ThemeManager $themes)
    {
        $settings = $this->settingsFor($storefront, $themes);
        $this->putStyles($settings, array_values(array_filter($this->styles($settings), fn (array $s) => $s['key'] !== $style)));

        return redirect()->route('admin.themes.customize', [$storefront, 'section' => 'presets'])->with('status', __('Style deleted.'));
    }

    /** @return list<array{key: string, name: string, values: array<string, mixed>, created_at: string}> */
    protected function styles(ThemeSettings $settings): array
    {
        $styles = app(\App\Core\Settings\SettingsManager::class)->get($settings->moduleId(), self::STYLES, []);

        return array_values(array_filter((array) $styles, fn ($s) => is_array($s) && isset($s['key'], $s['name']) && is_array($s['values'] ?? null)));
    }

    /** @param list<array<string, mixed>> $styles */
    protected function putStyles(ThemeSettings $settings, array $styles): void
    {
        app(\App\Core\Settings\SettingsManager::class)->set($settings->moduleId(), self::STYLES, $styles);
    }

    /**
     * The theme's ready-made colour combinations (`colour_schemes`): a name
     * and some colours (and the dark scheme's tone), applied in the configurator's form — not saved until
     * Save, so one can be tried and adjusted. Only colours the theme declares.
     *
     * @return list<array{key: string, name: string, colours: array<string, string>}>
     */
    protected function colourSchemes(ThemeManager $themes, ThemeSettings $settings): array
    {
        $out = [];

        foreach ($themes->chain() as $theme) {
            foreach ((array) ($themes->meta($theme)['colour_schemes'] ?? []) as $scheme) {
                if (empty($scheme['key']) || isset($out[$scheme['key']])) {
                    continue;
                }

                $colours = array_filter(
                    $settings->clean((array) ($scheme['colours'] ?? [])),
                    // Colours, and a field that says it belongs with them
                    // (`scheme`: the dark scheme's tone).
                    fn ($v, $k) => in_array($settings->schema()[$k]['type'] ?? null, ['color', 'color_dark'], true) || ! empty($settings->schema()[$k]['scheme']),
                    ARRAY_FILTER_USE_BOTH,
                );

                $out[$scheme['key']] = ['key' => (string) $scheme['key'], 'name' => $this->label($scheme['name'] ?? $scheme['key']), 'colours' => $colours];
            }
        }

        return array_values($out);
    }

    /** "Share the settings": this storefront's values as a file. */
    public function export(Storefront $storefront, ThemeManager $themes)
    {
        $payload = [
            'theme' => $storefront->theme,
            'values' => $this->saved($storefront, $this->settingsFor($storefront, $themes)),
        ];

        return response()->streamDownload(
            fn () => print json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'theme-'.$storefront->theme.'-'.$storefront->code.'.json',
            ['Content-Type' => 'application/json'],
        );
    }

    /**
     * ...and back. Only keys this theme declares, only answers its fields
     * can give (ThemeSettings::clean) — a file from another theme applies
     * whatever the two have in common and nothing else.
     */
    public function import(Request $request, Storefront $storefront, ThemeManager $themes)
    {
        $request->validate(['file' => ['required', 'file', 'max:64']]);

        $data = json_decode((string) file_get_contents($request->file('file')->getRealPath()), true);
        $values = is_array($data) ? (array) ($data['values'] ?? $data) : [];

        $settings = $this->settingsFor($storefront, $themes);
        $clean = $settings->clean($values);

        // Said on the screen, as everything else here is — not an error page.
        if ($clean === []) {
            return redirect()->route('admin.themes.customize', $storefront)
                ->withErrors(['file' => is_array($data)
                    ? __('Nothing in that file is a setting of this theme.')
                    : __('That is not a settings file: download one with "Download the settings".')]);
        }

        $settings->save($storefront, $clean);

        return redirect()->route('admin.themes.customize', $storefront)->with('status', trans_choice(':count setting taken from the file.|:count settings taken from the file.', count($clean)));
    }

    protected function settingsFor(Storefront $storefront, ThemeManager $themes): ThemeSettings
    {
        $themes->setActive($storefront->theme ?: config('torgash.default_theme', 'default'));

        return tap(app(ThemeSettings::class))->flush();
    }

    /** @return array<string, mixed> the storefront's SAVED values, never a draft */
    protected function saved(Storefront $storefront, ThemeSettings $settings): array
    {
        $out = [];

        foreach ($settings->schema() as $key => $field) {
            $out[$key] = $settings->stored($settings->moduleId($storefront->theme), $key, $field, $storefront->id);
        }

        return $out;
    }

    /** @return list<array<string, mixed>> the active chain's presets, a child's first */
    protected function presets(ThemeManager $themes): array
    {
        $out = [];

        foreach ($themes->chain() as $theme) {
            foreach ((array) ($themes->meta($theme)['presets'] ?? []) as $preset) {
                if (! empty($preset['key']) && ! isset($out[$preset['key']])) {
                    $out[$preset['key']] = $preset + [
                        'preview_url' => ! empty($preset['preview']) ? $this->asset($theme, (string) $preset['preview']) : null,
                    ];
                }
            }
        }

        return array_values($out);
    }

    /** @return array<string, string> section key => its name, a child's naming first */
    protected function sectionLabels(ThemeManager $themes): array
    {
        $out = [];

        foreach (array_reverse($themes->chain()) as $theme) {
            foreach ((array) ($themes->meta($theme)['setting_sections'] ?? []) as $key => $label) {
                $out[$key] = $this->label($label);
            }
        }

        return $out;
    }

    /**
     * A section's icon and the screen it is best looked at on — theme.json's
     * optional `setting_section_meta` ({"mobile": {"icon": "smartphone",
     * "device": "phone"}}), a child's word first.
     *
     * @return array<string, array{icon?: string, device?: string}>
     */
    protected function sectionMeta(ThemeManager $themes): array
    {
        $out = [];

        foreach (array_reverse($themes->chain()) as $theme) {
            foreach ((array) ($themes->meta($theme)['setting_section_meta'] ?? []) as $key => $meta) {
                $out[$key] = (array) $meta + ($out[$key] ?? []);
            }
        }

        return $out;
    }

    /**
     * The pages the frame can be sent to: the home page and the account from
     * core, the rest from whoever owns them (`core.theme.preview_pages`, items
     * of ['label' => English, 'icon' => …, 'path' => '/…', 'position' => 0–100]) — core does not
     * know there is a catalogue. Each under the storefront's own address.
     *
     * @return list<array{label: string, icon: string, url: string}>
     */
    protected function previewPages(Storefront $storefront): array
    {
        $pages = [
            ['label' => 'Home page', 'icon' => 'house', 'path' => '/', 'position' => 0],
            ['label' => 'My account', 'icon' => 'user', 'path' => route('account.dashboard', [], false), 'position' => 90],
        ];
        $pages = (array) hook_filter('core.theme.preview_pages', $pages, ['storefront' => $storefront]);
        // In the order a visitor walks the shop: 'position', 50 when unsaid.
        usort($pages, fn ($a, $b) => ((int) ($a['position'] ?? 50)) <=> ((int) ($b['position'] ?? 50)));

        $base = rtrim($this->storefrontUrl($storefront), '/');

        return array_values(array_filter(array_map(fn ($page) => is_array($page) && ! empty($page['path']) ? [
            'label' => label_text($page['label'] ?? $page['path']),
            'icon' => (string) ($page['icon'] ?? 'file'),
            'url' => $base.'/'.ltrim((string) $page['path'], '/'),
        ] : null, $pages)));
    }

    protected function previewImage(string $dir, array $meta): ?string
    {
        return ! empty($meta['preview']) ? $this->asset($dir, (string) $meta['preview']) : null;
    }

    protected function asset(string $theme, string $path): ?string
    {
        $file = config('torgash.themes_path').DIRECTORY_SEPARATOR.$theme.DIRECTORY_SEPARATOR.'assets'.DIRECTORY_SEPARATOR.$path;

        return is_file($file) ? theme_asset($theme, $path) : null;
    }

    protected function label(mixed $label): string
    {
        return label_text($label);
    }

    /**
     * The storefront's own address. The frame shows the draft only when it
     * shares this session — the same origin as the admin — which is the
     * ordinary set-up; a storefront on another domain previews what is saved.
     */
    protected function storefrontUrl(Storefront $storefront): string
    {
        return $storefront->url() ?? url('/');
    }
}
