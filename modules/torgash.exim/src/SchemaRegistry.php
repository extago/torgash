<?php

namespace Torgash\Exim;

use App\Models\Language;
use Illuminate\Database\Eloquent\Model;

/**
 * Thin wrapper around the hook accumulator idiom, not a registry object —
 * mirrors catalog.product.buy_box (modules/torgash.catalog/views/product.blade.php)
 * rather than MediaOwners: exim never needs to enumerate "who might answer"
 * ahead of time, it just asks at render/import time.
 *
 * A field def is a plain array:
 *   ['key' => 'sku', 'label' => 'SKU', 'get' => fn($model, array $ctx) => ..., 'set' => fn($model, $value) => void]
 * `set` is null for computed/read-only fields (e.g. a resolved price).
 *
 * Optional keys:
 *   'group'        where the profile form lists it (GROUPS; default `main`)
 *   'translatable' true — ONE declaration, expanded here into one column per
 *                  language of the shop (`name_en`, `name_uk`, …). The module
 *                  never names a language: a shop adding its tenth one gets
 *                  the tenth column without anybody touching a schema.
 *                  `get`/`set` then take the locale as their last argument,
 *                  and may be left out entirely when the field is a plain
 *                  jsonb translation column named by `attribute` (or `key`).
 *   'empty'        what a translatable column holds once every language is
 *                  cleared: NULL by default, `[]` for a NOT NULL one.
 *   'after'        fn($model, $value) run AFTER the row is saved — for data
 *                  that lives in another table and needs the row's id (a
 *                  price-type override).
 *
 * For reading OTHER systems' files (PLAN §58, Import\Columns):
 *   'aliases'        what other systems call the column ("Product code") —
 *                    on a translatable field it names the content's base
 *                    language column only;
 *   'alias_patterns' regular expressions for a family of headers
 *                    ("VAR Feature 2 [Цвет]");
 *   'per_column'     the field takes any number of columns and is told which
 *                    one (`$ctx['column']`) — "a characteristic named by the
 *                    column";
 *   'import_only'    no `get`: never a column of an export;
 *   'on_request'     exported only when a profile ticks it — not part of
 *                    "every field" (a profile that ticks nothing), so a new
 *                    column never appears in a feed nobody changed;
 *   'template_only'  its value is a LIST (a feed's pictures): only a
 *                    template can print it, never a cell;
 *   'with'           relations the `get` reads, eager-loaded by the export
 *                    when the column is in it (else one query per row);
 *   'help'           one line under the field on the mapping screen;
 *   'hint'           a few muted characters beside its name there, also
 *                    searched by — a characteristic's `#id`, since two may
 *                    share a name.
 * A translatable field is listed there once; the admin picks the language a
 * column fills, or all of them (`name_*`, Import\Columns::ALL).
 * `set` and `after` are called as fn($model, $value, array $ctx), `$ctx`
 * holding `column`, `row` (the whole row), `options` (the import's settings)
 * and `batch` (Import\ImportBatch) — a closure that ignores it simply takes
 * two arguments.
 */
class SchemaRegistry
{
    /** The profile form's sections, in order. A group nobody declared lands in `other`. */
    public const GROUPS = [
        'main' => 'Basics',
        'texts' => 'Texts',
        'prices' => 'Prices',
        'stock' => 'Stock and dimensions',
        'features' => 'Characteristics',
        'media' => 'Photos',
        'seo' => 'SEO',
        'other' => 'Other',
    ];

    /** @var list<string>|null */
    protected ?array $locales = null;

    /**
     * Every column the entity can have: translatable fields expanded into one
     * column per language. Import reads this — a file may carry any language.
     *
     * @param  list<string>|null  $locales  narrow the languages (an export profile's choice)
     * @return list<array{key:string,label:string,get:callable,set:?callable}>
     */
    public function fieldsFor(string $entity, ?array $locales = null): array
    {
        $columns = [];

        foreach ($this->declared($entity) as $field) {
            array_push($columns, ...$this->expand($field, $locales));
        }

        return $columns;
    }

    /**
     * The fields as their modules DECLARED them — one "Name", not one per
     * language. The profile form ticks these.
     *
     * @return list<array>
     */
    public function declared(string $entity): array
    {
        return array_map(fn (array $field) => $field + ['group' => 'main', 'translatable' => false],
            hook_filter("exim.schema.{$entity}", [], ['entity' => $entity]));
    }

    /**
     * The columns an export profile writes: its ticked fields (all when none
     * is ticked), each in the profile's languages.
     *
     * A key stored before fields became translatable (`name_ru`) still names
     * its one column, so a profile saved last month exports what it did.
     *
     * @param  list<string>  $selected
     * @param  list<string>|null  $locales
     */
    public function columnsFor(string $entity, array $selected, ?array $locales = null): array
    {
        $declared = collect($this->declared($entity))->keyBy('key');

        if ($selected === []) {
            return array_values(array_filter($this->fieldsFor($entity, $locales), fn (array $column) => ! ($column['import_only'] ?? false) && ! ($column['on_request'] ?? false)));
        }

        $every = collect($this->fieldsFor($entity))->reject(fn (array $column) => $column['import_only'] ?? false)->keyBy('key');
        $declared = $declared->reject(fn (array $field) => $field['import_only'] ?? false);
        $columns = [];

        foreach ($selected as $key) {
            if ($field = $declared->get($key)) {
                array_push($columns, ...$this->expand($field, $locales));
            } elseif ($column = $every->get($key)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    /** declared(), less the fields that only an import reads — what an export profile can tick. */
    public function exportable(string $entity): array
    {
        return array_values(array_filter($this->declared($entity), fn (array $field) => ! ($field['import_only'] ?? false) && ! ($field['template_only'] ?? false)));
    }

    public function entities(): array
    {
        return hook_filter('exim.entities', [], []);
    }

    /** The shop's languages, in its own order. */
    public function locales(): array
    {
        return $this->locales ??= Language::query()->where('enabled', true)
            ->orderBy('sort')->orderBy('id')->pluck('code')->all() ?: [content_base_locale()];
    }

    /** What a column is called on screen: the translated label, plus the language it is in. */
    public static function title(array $column): string
    {
        $suffix = isset($column['locale']) ? strtoupper($column['locale']) : ($column['suffix'] ?? null);

        return __($column['base_label'] ?? $column['label']).($suffix !== null ? ' ('.$suffix.')' : '');
    }

    /** @return list<array> */
    protected function expand(array $field, ?array $locales): array
    {
        if (! $field['translatable']) {
            return [$field];
        }

        $locales = $locales ? array_values(array_intersect($locales, $this->locales())) : $this->locales();
        $attribute = $field['attribute'] ?? $field['key'];
        $columns = [];

        foreach ($locales as $locale) {
            $get = $field['get'] ?? null;
            $set = array_key_exists('set', $field) ? $field['set'] : true;

            $columns[] = [
                ...$field,
                'key' => $field['key'].'_'.$locale,
                // The file's header stays English and names the language by
                // its code, which is what the importer reads back.
                'label' => $field['label'].' ('.strtoupper($locale).')',
                // Another system's spelling names the shop's own language.
                'aliases' => $locale === content_base_locale() ? (array) ($field['aliases'] ?? []) : [],
                'base_key' => $field['key'],
                'base_label' => $field['label'],
                'locale' => $locale,
                'get' => $get
                    ? fn (Model $model, array $ctx = []) => $get($model, $ctx, $locale)
                    : fn (Model $model) => ((array) ($model->{$attribute} ?? []))[$locale] ?? null,
                'set' => match (true) {
                    $set === null => null,
                    $set === true => fn (Model $model, $value) => self::writeTranslation($model, $attribute, $locale, $value, $field['empty'] ?? null),
                    default => fn (Model $model, $value) => $set($model, $value, $locale),
                },
            ];
        }

        return $columns;
    }

    /**
     * One language of a jsonb translation column. A blank cell CLEARS that
     * language rather than storing "" — "" and "not stated" have to be the
     * same thing, exactly as on the forms — and the others are left alone.
     */
    public static function writeTranslation(Model $model, string $attribute, string $locale, mixed $value, ?array $empty = null): void
    {
        $values = (array) ($model->{$attribute} ?? []);
        $value = trim((string) $value);

        if ($value === '') {
            unset($values[$locale]);
        } else {
            $values[$locale] = $value;
        }

        // `empty` is what a column that may not be NULL holds with nothing in
        // it (a name is NOT NULL; a meta title simply absent).
        $model->{$attribute} = $values === [] ? $empty : $values;
    }
}
