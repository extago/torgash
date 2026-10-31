<?php

namespace App\Http\Controllers\Admin;

use App\Core\Admin\AdminMenu;
use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleManifest;
use App\Core\Settings\CoreSettings;
use App\Core\Settings\SettingsManager;
use App\Core\Support\SafeSvg;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SettingsController extends Controller
{
    /** Where an `image` setting's file is kept on the public disk. */
    public const IMAGE_DIR = 'settings';

    public function index(ModuleManager $modules, SettingsManager $settings)
    {
        $menu = app(AdminMenu::class);

        // Filed under the sidebar's own sections, in the sidebar's order, so
        // "where are the cart's settings" has the answer the menu already
        // taught: under Sales. The shop's own come first, under its name.
        $groups = ['shop' => [
            'label' => __('Store'),
            'icon' => 'store',
            'sections' => [$this->section(CoreSettings::manifest(), $settings)],
        ]];

        foreach ($menu->sectionHeadings() as $key => $heading) {
            $groups[$key] = $heading + ['sections' => []];
        }

        foreach ($modules->enabled() as $manifest) {
            if (! $manifest->hasSettings()) {
                continue;
            }

            $groups[$menu->sectionOf($manifest)]['sections'][] = $this->section($manifest, $settings);
        }

        $groups = array_filter($groups, fn ($group) => $group['sections'] !== []);

        foreach ($groups as &$group) {
            // By the name the reader sees, in the reader's language.
            usort($group['sections'], fn ($a, $b) => strcmp(label_text($a['module']->name), label_text($b['module']->name)));
        }
        unset($group);

        return view('admin.settings.index', [
            'groups' => $groups,
            'sections' => array_merge(...array_values(array_column($groups, 'sections'))),
        ]);
    }

    /** Single-module settings screen — what the gear on /admin/modules links to. */
    public function edit(string $module, ModuleManager $modules, SettingsManager $settings)
    {
        $manifest = $this->configurable($module, $modules);
        $section = $this->section($manifest, $settings);

        return view('admin.settings.index', [
            'sections' => [$section],
            'only' => $manifest,
            // The module's own screens, the ones this reader may open — a
            // settings page is where people look for "and where do I USE it".
            'screens' => array_values(array_filter(
                array_map(fn ($item) => $item + [
                    'title' => label_text($item['label'] ?? null, null, '?'),
                    'href' => url($item['url'] ?? '#'),
                ], $manifest->adminMenu),
                fn ($item) => ! isset($item['can']) || auth()->user()?->can($item['can']),
            )),
            'changed' => $this->changed($section),
        ]);
    }

    /**
     * Every setting of one module back to what its manifest says — the stored
     * shop-wide rows are removed (a storefront's own overrides are left
     * alone: they were decided per storefront, not here).
     */
    public function reset(string $module, ModuleManager $modules, SettingsManager $settings)
    {
        $manifest = $this->configurable($module, $modules);

        foreach ($this->stored($manifest) as $def) {
            $settings->forget($module, $def['key']);
        }

        return back()->with('status', __('Settings returned to their defaults.'));
    }

    /**
     * Keys whose value differs from the declared default — marked on the page,
     * so "what did we change here" has an answer without reading every field.
     *
     * @return array<string, true>
     */
    protected function changed(array $section): array
    {
        $changed = [];

        foreach ($section['fields'] as $field) {
            if (! isset($field['key']) || ! array_key_exists('value', $field)) {
                continue;
            }

            $default = $field['default'] ?? null;
            $value = $field['value'];
            $same = is_array($value) || is_array($default)
                ? array_map('strval', (array) $value) == array_map('strval', (array) $default)
                : (($field['type'] ?? 'text') === 'checkbox'
                    ? (bool) $value === (bool) $default
                    : (string) $value === (string) $default);

            if (! $same) {
                $changed[$field['key']] = true;
            }
        }

        return $changed;
    }

    public function update(Request $request, string $module, ModuleManager $modules, SettingsManager $settings)
    {
        $manifest = $this->configurable($module, $modules);
        $values = [];

        foreach ($this->stored($manifest) as $def) {
            $key = $def['key'];

            $values[$key] = match ($def['type'] ?? 'text') {
                'checkbox' => $request->boolean($key),
                'number' => $request->filled($key) ? (float) $request->input($key) : null,
                // Ticking nothing submits nothing at all, so "none chosen" and
                // "the field never rendered" arrive identical — fine here,
                // where the form always carries every declared setting.
                // Only declared options are stored, in the order the manifest
                // lists them, so a code left over from an older version of the
                // module cannot linger in the value nobody looks at again.
                'checkboxes' => array_values(array_intersect(
                    array_map('strval', array_keys($def['options'] ?? [])),
                    array_map('strval', (array) $request->input($key, [])),
                )),
                'image' => $this->image($request, $key, (string) $settings->get($module, $key, '')),
                default => $request->input($key),
            };
        }

        // A module's say on its own values before any is written — "the next
        // order number is below the last one issued" — keyed by field, so
        // one refusal keeps the whole page as it was.
        $refusals = hook_filter('core.settings.saving', [], ['module' => $module, 'values' => $values]);

        if ($refusals !== []) {
            throw ValidationException::withMessages($refusals);
        }

        foreach ($values as $key => $value) {
            $settings->set($module, $key, $value);
        }

        return back()->with('status', __('Settings saved.'));
    }

    /**
     * An `image` setting's new value: the address of the file just uploaded
     * (kept on the public disk under settings/, an SVG cleaned first — the
     * same rules as a theme's pictures), '' when the admin ticked "remove",
     * otherwise what was there. A file this screen put there earlier is
     * deleted when replaced or removed; an address typed by hand (the old
     * shape of the preview picture) is only forgotten.
     */
    protected function image(Request $request, string $key, string $current): string
    {
        $disk = Storage::disk('public');
        $ours = fn (string $url) => $url !== '' && str_starts_with($url, $disk->url(self::IMAGE_DIR.'/'));

        if (! $request->hasFile($key)) {
            if (! $request->boolean($key.'__remove')) {
                return $current;
            }
            if ($ours($current)) {
                $disk->delete(self::IMAGE_DIR.'/'.basename($current));
            }

            return '';
        }

        $request->validate([$key => ['file', 'max:2048']]);
        $file = $request->file($key);
        $name = self::IMAGE_DIR.'/'.str_replace('.', '-', $key).'-'.Str::random(12);

        if (strtolower($file->getClientOriginalExtension()) === 'svg' || $file->getMimeType() === 'image/svg+xml') {
            $svg = SafeSvg::clean((string) file_get_contents($file->getRealPath()));
            if ($svg === null) {
                throw ValidationException::withMessages([$key => __('This SVG could not be read as a drawing.')]);
            }
            $path = $name.'.svg';
            $disk->put($path, $svg);
        } else {
            $request->validate([$key => ['image', 'mimes:png,jpg,jpeg,webp,gif']]);
            $path = $file->storeAs(dirname($name), basename($name).'.'.strtolower($file->extension()), 'public');
        }

        if ($ours($current)) {
            $disk->delete(self::IMAGE_DIR.'/'.basename($current));
        }

        return $disk->url($path);
    }

    protected function configurable(string $module, ModuleManager $modules): ModuleManifest
    {
        if ($module === CoreSettings::ID) {
            return CoreSettings::manifest();
        }

        $manifest = $modules->find($module);
        abort_unless($manifest && $modules->isEnabled($module) && $manifest->hasSettings(), 404);

        return $manifest;
    }

    /** Definitions plus current values, with "group" headings kept in place for layout. */
    protected function section(ModuleManifest $manifest, SettingsManager $settings): array
    {
        return [
            'module' => $manifest,
            'fields' => array_map(
                fn ($def) => in_array($def['type'] ?? 'text', ['group', 'action', 'link'], true)
                    ? $def
                    : $def + ['value' => $settings->get($manifest->id, $def['key'], $def['default'] ?? null)],
                $this->definitions($manifest),
            ),
        ];
    }

    /**
     * A module's settings as they are actually offered, which is not always
     * what its manifest.json could say.
     *
     * Some options only exist in the shop's own data — the order statuses a
     * return is allowed from, a warehouse, a customer group — and a JSON file
     * written months earlier cannot list them. So the definitions pass through
     * a filter on the way to the screen, and the module answering it fills in
     * its own options. The alternative was an admin page per module for one
     * field, which is how a settings screen stops being one place.
     *
     * Read here and in update() through the same helper on purpose: options
     * added on the way out have to be the ones validated on the way back, or
     * ticking a box would save nothing.
     *
     * @return list<array<string, mixed>>
     */
    protected function definitions(ModuleManifest $manifest): array
    {
        return hook_filter('core.settings.fields', $manifest->settings, ['module' => $manifest->id]);
    }

    /**
     * The same list, minus the entries that render but hold no value —
     * ModuleManifest::storedSettings() over the hooked definitions rather than
     * the raw ones.
     *
     * @return list<array<string, mixed>>
     */
    protected function stored(ModuleManifest $manifest): array
    {
        return array_values(array_filter(
            $this->definitions($manifest),
            fn ($def) => isset($def['key'])
                && ! in_array($def['type'] ?? 'text', ['group', 'action', 'link'], true),
        ));
    }
}
