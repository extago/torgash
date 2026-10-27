<?php

namespace App\Core\Admin;

use App\Core\Modules\ModuleManager;
use App\Core\Modules\ModuleManifest;
use App\Core\Settings\CoreSettings;

/**
 * "Where is that set?" — one box in the admin header that finds a SCREEN or a
 * SETTING by the words a person would use for it.
 *
 * Nothing is indexed by hand and nothing is stored: every answer is read from
 * what already describes the admin — the sidebar's own items, every module's
 * `admin_menu`, and every setting a settings screen would draw (through the
 * same `core.settings.fields` filter, so options a module fills in at runtime
 * are searchable too). A module adds what none of those can say — its own
 * rows, like the shop's availability statuses — by answering
 * `core.admin.search` with entries of the same shape. A screen or a setting
 * may also carry `keywords` ({"en": …, "ru": …}) for the word a person types
 * that its label does not contain ("preorder" for "Availability statuses").
 *
 * An entry is matched in EVERY language it is written in, not only the one
 * the admin is reading: a manager with a Russian interface who remembers the
 * English word still finds it. Every word typed must occur somewhere (words,
 * not a phrase — "статус наличия" finds "Статусы наличия"), and a hit in the
 * title outranks one in the help text or an option.
 *
 * Built per call and never cached: the answer depends on which modules are
 * enabled, the admin's permissions and the shop's own data, and it is asked
 * only by someone typing into a box.
 */
class AdminSearch
{
    /** @var list<string> the shop's languages, refreshed by entries() */
    protected static array $locales = [];

    public function __construct(protected ModuleManager $modules) {}

    /**
     * @return list<array{title: string, context: string, url: string, icon: string}>
     */
    public function search(string $query, int $limit = 20): array
    {
        $words = array_values(array_filter(preg_split('/\s+/u', self::normalise($query)) ?: []));

        if ($words === []) {
            return [];
        }

        $hits = [];

        foreach ($this->entries() as $position => $entry) {
            $title = self::normalise(implode(' ', $entry['titles']));
            $body = self::normalise(implode(' ', [$entry['context'], ...$entry['words']]));
            $extra = self::normalise(implode(' ', $entry['options']));
            $score = 0;

            foreach ($words as $word) {
                if (preg_match('/(^|[^\p{L}\p{N}])'.preg_quote($word, '/').'/u', $title)) {
                    $score += 6;
                } elseif (str_contains($title, $word)) {
                    $score += 4;
                } elseif (str_contains($body, $word)) {
                    $score += 2;
                } elseif (str_contains($extra, $word)) {
                    $score += 1;
                } else {
                    continue 2;
                }
            }

            // A screen before a setting at the same score: it is the wider answer.
            $hits[] = ['score' => $score, 'kind' => $entry['kind'] === 'screen' ? 1 : 0, 'position' => $position, 'entry' => $entry];
        }

        usort($hits, fn ($a, $b) => [$b['score'], $b['kind'], $a['position']] <=> [$a['score'], $a['kind'], $b['position']]);

        return array_map(fn ($hit) => [
            'title' => $hit['entry']['title'],
            'context' => $hit['entry']['context'],
            'url' => $hit['entry']['url'],
            'icon' => $hit['entry']['icon'],
        ], array_slice($hits, 0, $limit));
    }

    /**
     * Everything that can be found, in the order the sidebar reads.
     *
     * @return list<array{kind: string, title: string, titles: list<string>, context: string, words: list<string>, options: list<string>, url: string, icon: string}>
     */
    public function entries(): array
    {
        // The shop's languages, asked once per list rather than per entry;
        // set here, where every call starts, so a worker's next request
        // never reads the last one's.
        self::$locales = \App\Models\Language::query()->where('enabled', true)->pluck('code')->all();
        $entries = [];

        foreach ($this->coreScreens() as $screen) {
            if (isset($screen['can']) && ! auth()->user()?->can($screen['can'])) {
                continue;
            }

            $entries[] = $this->entry('screen', [__($screen['label']), $screen['label']], '', [], $screen['url'], $screen['icon']);
        }

        foreach ($this->modules->adminMenu() as $item) {
            if (isset($item['can']) && ! auth()->user()?->can($item['can'])) {
                continue;
            }

            $entries[] = $this->entry(
                'screen',
                self::strings($item['label'] ?? []),
                __('Menu'),
                self::strings($item['keywords'] ?? []),
                url($item['url'] ?? '#'),
                $item['icon'] ?? 'tag',
            );
        }

        $manifests = [CoreSettings::manifest()];

        foreach ($this->modules->enabled() as $manifest) {
            if ($manifest->hasSettings()) {
                $manifests[] = $manifest;
            }
        }

        // A setting is found only by someone who may open the page it is on.
        if (auth()->user()?->can('settings.manage')) {
            foreach ($manifests as $manifest) {
                array_push($entries, ...$this->settings($manifest));
            }
        }

        foreach (hook_filter('core.admin.search', [], []) as $extra) {
            if (! isset($extra['title'], $extra['url'])) {
                continue;
            }

            $titles = self::strings($extra['title']);
            $entries[] = $this->entry(
                $extra['kind'] ?? 'screen',
                $titles,
                (string) ($extra['context'] ?? ''),
                self::strings($extra['keywords'] ?? []),
                (string) $extra['url'],
                (string) ($extra['icon'] ?? 'arrow-right'),
            );
        }

        return $entries;
    }

    /** @return list<array<string, mixed>> */
    protected function settings(ModuleManifest $manifest): array
    {
        $entries = [];
        $group = null;
        $page = route('admin.settings.edit', $manifest->id);

        foreach (hook_filter('core.settings.fields', $manifest->settings, ['module' => $manifest->id]) as $field) {
            $type = $field['type'] ?? 'text';

            if ($type === 'group') {
                $group = self::localised($field['label'] ?? []);

                continue;
            }

            if (! isset($field['key']) && ! ($type === 'link' && isset($field['url']))) {
                continue;
            }

            $options = [];

            foreach ($field['options'] ?? [] as $option) {
                array_push($options, ...(is_array($option) ? self::strings($option) : [__((string) $option), (string) $option]));
            }

            $entry = $this->entry(
                'setting',
                self::strings($field['label'] ?? $field['key'] ?? ''),
                implode(' · ', array_filter([__('Settings'), $manifest->name, $group])),
                [...self::strings($field['help'] ?? []), ...self::strings($field['keywords'] ?? []), (string) ($field['key'] ?? '')],
                // A link field IS a screen; everything else is found where it is drawn.
                $type === 'link' ? url($field['url']) : $page.'#setting-'.$field['key'],
                $type === 'link' ? 'arrow-right' : 'sliders-horizontal',
            );
            $entry['options'] = $options;
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * The sidebar's own items — core's screens, which no manifest describes.
     * Kept in the sidebar's order; a screen added to the layout belongs here too.
     *
     * @return list<array{label: string, url: string, icon: string, can?: string}>
     */
    /**
     * Core's screens — read from the sidebar's own list (AdminMenu), so a
     * screen added to the sidebar is found here without a second list to
     * forget. The dashboard is the one entry the sidebar draws outside a
     * section.
     */
    protected function coreScreens(): array
    {
        return [
            ['label' => 'Dashboard', 'url' => route('admin.dashboard'), 'icon' => 'house'],
            ...app(AdminMenu::class)->coreItems(),
        ];
    }

    /** @param list<string> $titles */
    protected function entry(string $kind, array $titles, string $context, array $words, string $url, string $icon): array
    {
        $titles = array_values(array_unique(array_filter($titles, fn ($t) => $t !== '')));

        return [
            'kind' => $kind,
            // The admin's own language first, when the entry has it.
            'title' => $titles[0] ?? '',
            'titles' => $titles,
            'context' => $context,
            'words' => $words,
            'options' => [],
            'url' => $url,
            'icon' => $icon,
        ];
    }

    /**
     * Every language of a `{"en": …, "ru": …}` map, the admin's own first — or
     * a plain string as it is.
     *
     * @return list<string>
     */
    protected static function strings(mixed $value): array
    {
        // A declared word is English with its translations in lang files: the
        // reader's language first, then the English and every other language
        // the shop speaks — somebody typing the Russian word into an English
        // admin still finds it, as with a {en, ru} map.
        if (is_string($value)) {
            $words = [label_text($value), $value];

            foreach (self::$locales as $locale) {
                $words[] = label_text($value, $locale);
            }

            return array_values(array_unique(array_filter($words, fn ($w) => $w !== '')));
        }

        if (! is_array($value)) {
            return [];
        }

        $locale = app()->getLocale();
        $own = isset($value[$locale]) && is_string($value[$locale]) ? [$value[$locale]] : [];

        return [...$own, ...array_values(array_filter($value, fn ($v, $k) => is_string($v) && $k !== $locale, ARRAY_FILTER_USE_BOTH))];
    }

    protected static function localised(mixed $value): ?string
    {
        return self::strings($value)[0] ?? null;
    }

    /** Lower case, and `ё` read as `е` — nobody types the dots. */
    public static function normalise(string $text): string
    {
        return str_replace('ё', 'е', mb_strtolower(trim($text)));
    }
}
