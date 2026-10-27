<?php

namespace Torgash\Banners;

use App\Core\Display\DisplayRules;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;
use Torgash\Banners\Models\Banner;
use Torgash\Banners\Models\Slider;
use Torgash\Banners\Models\Template;
use Torgash\Media\ImageProcessor;

/**
 * Banners in a spreadsheet (PLAN §51, step 5).
 *
 * A banner is the values of its template's fields, and templates are the
 * shop's own, so the columns are too: one per field any banner template has
 * — a word one per language, a picture its address, a list of links one JSON
 * cell — after the banner's own (template, name, dates, sliders). A field
 * another template has stays empty on this row and is not written.
 *
 * A row is matched by its `id`: the file a shop exported updates the shop's
 * own banners; in another shop the ids mean nothing and the rows are new.
 * Templates themselves travel as their own JSON files (Templates → Export).
 *
 * `torgash.exim` is not in this module's requires: these registrations only
 * populate the hook bus, and nothing fires unless exim itself asks.
 */
class BannerExim
{
    public function register(): void
    {
        hook_listen('exim.entities', fn (array $v) => $v + ['banner' => 'Banner']);

        hook_listen('exim.public_entities', fn (array $v) => [...$v, 'banner']);

        hook_listen('exim.query.banner', function ($v, array $ctx) {
            $query = Banner::query()->with(['template', 'media', 'sliders:id,name'])->orderBy('id');

            if ($ctx['filters']['enabled_only'] ?? false) {
                $query->where('enabled', true);
            }

            return $query;
        });

        hook_listen('exim.match.banner', function ($v, array $ctx) {
            $id = (int) ($ctx['row']['id'] ?? 0);
            $existing = $id ? Banner::query()->find($id) : null;

            // A new one starts in the first banner template; its own "Template" column says otherwise.
            $new = new Banner(['values' => [], 'enabled' => true, 'name' => 'Banner',
                'template_id' => Template::query()->kind('banner')->orderBy('id')->value('id')]);

            return ['model' => $existing ?? $new, 'matchedBy' => $existing ? 'id' : null];
        });

        hook_listen('exim.schema.banner', fn (array $fields) => [...$fields, ...$this->fields()]);
    }

    /** @return list<array<string, mixed>> */
    protected function fields(): array
    {
        $fields = [
            ['key' => 'id', 'label' => 'ID', 'get' => fn (Banner $b) => $b->id, 'set' => null],
            // First, so the values below know which template they belong to.
            ['key' => 'template', 'label' => 'Template', 'get' => fn (Banner $b) => $b->template?->key, 'set' => function (Banner $b, $v) {
                $b->template_id = Template::query()->kind('banner')->where('key', trim((string) $v))->value('id')
                    ?? $b->template_id
                    ?? Template::query()->kind('banner')->orderBy('id')->value('id');
            }],
            ['key' => 'name', 'label' => 'Name', 'get' => fn (Banner $b) => $b->name, 'set' => fn (Banner $b, $v) => $b->name = mb_substr(trim((string) $v), 0, 190) ?: ($b->name ?: 'Banner')],
            ['key' => 'enabled', 'label' => 'Enabled', 'get' => fn (Banner $b) => $b->enabled, 'set' => fn (Banner $b, $v) => $b->enabled = in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'y', 'on', 'да'], true)],
            ['key' => 'starts_at', 'label' => 'Shown from', 'get' => fn (Banner $b) => $b->starts_at?->toDateTimeString(), 'set' => fn (Banner $b, $v) => $b->starts_at = $this->date($v)],
            ['key' => 'ends_at', 'label' => 'Shown until', 'get' => fn (Banner $b) => $b->ends_at?->toDateTimeString(), 'set' => fn (Banner $b, $v) => $b->ends_at = $this->date($v)],
            // The cell is the truth about which sliders it stands in; a name
            // the shop has no slider by is skipped, never made up.
            ['key' => 'sliders', 'label' => 'Sliders (names, comma separated)', 'get' => fn (Banner $b) => $b->sliders->pluck('name')->implode(', '), 'set' => null,
                'after' => fn (Banner $b, $v) => $this->sliders($b, (string) $v)],
            ['key' => 'rules', 'label' => 'Conditions (JSON)', 'get' => fn (Banner $b) => $b->rules ? json_encode(['match' => $b->rules_match, 'rules' => $b->rules], JSON_UNESCAPED_UNICODE) : '', 'set' => function (Banner $b, $v) {
                $data = json_decode((string) $v, true);
                $b->rules = is_array($data) ? app(DisplayRules::class)->normalise($data['rules'] ?? []) : null;
                $b->rules_match = ($data['match'] ?? '') === DisplayRules::MATCH_ANY ? DisplayRules::MATCH_ANY : DisplayRules::MATCH_ALL;
            }],
        ];

        foreach ($this->templateFields() as $field) {
            $fields[] = $this->column($field);
        }

        return $fields;
    }

    /**
     * Every field of every banner template, once, by key — the first
     * template's definition where two share a key.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function templateFields(): array
    {
        $fields = [];

        foreach (Template::query()->kind('banner')->orderBy('id')->get() as $template) {
            foreach ($template->fieldList() as $field) {
                $fields[$field['key']] ??= $field;
            }
        }

        return $fields;
    }

    /** One template field as a column (or, for words, one per language). */
    protected function column(array $field): array
    {
        $key = $field['key'];
        $base = ['key' => 'f_'.$key, 'label' => 'Field: '.$key];

        // The banner's own template's definition when it has the field: two
        // templates may give one key different choices.
        $own = function (Banner $b) use ($field) {
            return collect($b->template?->fieldList() ?? [])->firstWhere('key', $field['key']) ?? $field;
        };

        return match ($field['type']) {
            'text', 'rich' => $base + [
                'translatable' => true,
                'get' => fn (Banner $b, array $ctx, string $locale) => is_array($b->values[$key] ?? null) ? ($b->values[$key][$locale] ?? null) : null,
                'set' => function (Banner $b, $v, string $locale) use ($key, $own) {
                    $values = (array) $b->values;
                    $typed = Fields::accept($own($b), (string) $v) ?? '';
                    $values[$key] = put_translation(is_array($values[$key] ?? null) ? $values[$key] : null, $typed, $locale) ?: null;
                    $b->values = array_filter($values, fn ($value) => $value !== null);
                },
            ],
            'image' => $base + [
                'get' => fn (Banner $b) => $b->getFirstMedia(Fields::collection($key))?->getFullUrl(),
                'set' => null,
                // A picture by address, fetched when it is a new one; an empty
                // cell leaves the picture there — a file is not a way to delete.
                'after' => function (Banner $b, $v) use ($key) {
                    $url = trim((string) $v);
                    $current = $b->getFirstMedia(Fields::collection($key));

                    if ($url === '' || ($current && $current->getFullUrl() === $url)) {
                        return;
                    }

                    try {
                        $b->clearMediaCollection(Fields::collection($key));
                        app(ImageProcessor::class)->addFromUrl($b, $url, Fields::collection($key));
                    } catch (Throwable $e) {
                        report($e);
                    }
                },
            ],
            // A video goes out as its address; it is not fetched back in —
            // upload it on the banner's form.
            'video' => $base + [
                'get' => fn (Banner $b) => $b->getFirstMedia(Fields::collection($key))?->getFullUrl(),
                'set' => null,
            ],
            'links' => $base + [
                'get' => fn (Banner $b) => ($b->values[$key] ?? null) ? json_encode($b->values[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
                'set' => function (Banner $b, $v) use ($key) {
                    $rows = json_decode((string) $v, true);
                    $kept = [];

                    foreach (is_array($rows) ? array_slice($rows, 0, Fields::LINKS_MAX) : [] as $row) {
                        $text = array_filter((array) ($row['text'] ?? []), fn ($t) => is_string($t) && trim($t) !== '');
                        $link = trim((string) ($row['link'] ?? ''));

                        if ($text !== [] && preg_match('#^(https?://|/(?!/))#i', $link)) {
                            $kept[] = ['text' => array_map(fn ($t) => mb_substr(trim($t), 0, 100), $text), 'link' => $link];
                        }
                    }

                    $values = (array) $b->values;
                    $values[$key] = $kept ?: null;
                    $b->values = array_filter($values, fn ($value) => $value !== null);
                },
            ],
            default => $base + [
                'get' => fn (Banner $b) => is_bool($b->values[$key] ?? null) ? (int) $b->values[$key] : ($b->values[$key] ?? null),
                'set' => function (Banner $b, $v) use ($key, $own) {
                    $values = (array) $b->values;
                    $value = trim((string) $v) === '' ? null : Fields::accept($own($b), $v);

                    if (trim((string) $v) === '') {
                        unset($values[$key]);
                    } elseif ($value !== null) {
                        $values[$key] = $value;
                    }

                    $b->values = $values;
                },
            ],
        };
    }

    protected function sliders(Banner $banner, string $cell): void
    {
        $names = array_values(array_filter(array_map('trim', explode(',', $cell))));
        $wanted = Slider::query()->whereIn('name', $names)->pluck('id')->all();
        $current = $banner->sliders()->pluck('m_banners_sliders.id')->all();

        $banner->sliders()->detach(array_diff($current, $wanted));

        foreach (array_diff($wanted, $current) as $sliderId) {
            $last = (int) DB::table('m_banners_slider_banner')->where('slider_id', $sliderId)->max('position');
            $banner->sliders()->attach($sliderId, ['position' => $last + 1]);
        }
    }

    /** An unreadable date leaves the date empty rather than guessing. */
    protected function date(mixed $value): ?Carbon
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }
}
