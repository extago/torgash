<?php

namespace Torgash\Blocks;

/**
 * Registry of block types. A type is a declaration, registered from a module or
 * theme service provider:
 *
 *     BlockTypes::register('html', [
 *         'label'  => 'Raw HTML',
 *         'icon'   => 'code-xml',
 *         'view'   => 'torgash.blocks::blocks.html',
 *         'schema' => [
 *             ['key' => 'html', 'type' => 'html', 'label' => [...], 'permission' => 'blocks.html'],
 *         ],
 *     ]);
 *
 * The schema is the single definition behind BOTH admin editors: the generated
 * form renders one control per field, and the raw-JSON editor is validated
 * against the same list. They are two views of one thing rather than two
 * formats needing conversion, which is why neither can produce a value the
 * other could not — and why pasting JSON cannot introduce a key the type never
 * declared.
 *
 * `view` is a Blade template path on disk. It is the only place a block type
 * can execute anything, and it is code under version control, not a database
 * row.
 *
 * A select's `options` may be a CLOSURE returning the list. Registration runs at
 * boot on every request, and a list built from the shop's own rows (its
 * categories, its labels) would otherwise be a query paid by every page to
 * serve one admin form. An option may carry a `group`, drawn as an optgroup.
 *
 * A text field may carry `placeholder` and `short` (a narrow input, for a
 * value like "12px 16px"); a number field `suffix`, its unit drawn beside it.
 *
 * A `picks` field is an ORDERED list of ids picked from its `options` (rows of
 * the shop: categories, pages) — drawn as the admin's searchable picker, stored
 * as a JSON array in the order picked. An id that is no longer among the
 * options is dropped on save, never kept as a dangling reference.
 *
 * `description` is one sentence for the picker window and `fits` the zone
 * shapes the type makes sense in (see ZoneRegistry::SHAPES) — the picker
 * offers those first and keeps the rest one click away, because a shop
 * sometimes knows better than a list.
 *
 * `heading` (optional) is `fn (array $settings): string` — what the block is
 * CALLED to a visitor, given its settings already in their language. Asked only
 * when the block is one of several drawn as tabs (see ZoneRenderer), and
 * defaults to the `heading` setting every built-in type has.
 */
class BlockTypes
{
    /** Field types the generated form knows how to render and validate. */
    public const FIELD_TYPES = ['text', 'textarea', 'html', 'number', 'checkbox', 'select', 'picks'];

    /** Only text-shaped fields can hold translations; a number or a select cannot. */
    public const TRANSLATABLE_TYPES = ['text', 'textarea', 'html'];

    /** @var array<string, array<string,mixed>> */
    protected static array $types = [];

    public static function register(string $name, array $definition): void
    {
        static::$types[$name] = $definition + [
            'label' => ['en' => $name],
            'icon' => 'square',
            'schema' => [],
            'view' => null,
            'heading' => null,
            // One sentence for the picker: what the block DOES, as an English
            // source string translated by the owner's lang file.
            'description' => null,
            // The zone shapes it makes sense in (core's ZoneRegistry::SHAPES).
            // Content blocks default to the page's width and a side column; a
            // control meant for the header or the phone menu says so.
            'fits' => ['wide', 'narrow'],
        ];
    }

    /** Does this type make sense in a zone of that shape? The picker's only question. */
    public static function fits(string $name, string $shape): bool
    {
        return in_array($shape, (array) (static::find($name)['fits'] ?? ['wide', 'narrow']), true);
    }

    /** @return array<string, array<string,mixed>> */
    public static function all(): array
    {
        return static::$types;
    }

    public static function find(string $name): ?array
    {
        return static::$types[$name] ?? null;
    }

    public static function has(string $name): bool
    {
        return isset(static::$types[$name]);
    }

    public static function label(string $name, ?string $locale = null): string
    {
        $type = static::find($name);
        if (! $type) {
            return $name;
        }

        $locale ??= app()->getLocale();

        return label_text($type['label'] ?? null, $locale, $name);
    }

    /** Field definitions of a type, or [] for an unknown type. */
    public static function schema(string $name): array
    {
        return static::find($name)['schema'] ?? [];
    }

    public static function isTranslatable(array $field): bool
    {
        return ! empty($field['translatable'])
            && in_array($field['type'] ?? 'text', self::TRANSLATABLE_TYPES, true);
    }

    /**
     * A translatable setting holds one of two shapes, and BOTH are first-class:
     *
     *   "html": "<div>…</div>"                  the same in every language
     *   "html": {"en": "Sale", "ru": "Распродажа"}   per language
     *
     * The string form is not a legacy leftover — a block of markup with {tokens}
     * in it usually IS the same in every language, and forcing it into a map
     * would be ceremony. It becomes a map the moment someone types a second
     * language into it, with the old string kept as the base language's text.
     *
     * @return array<string,string> locale => text
     */
    public static function map(mixed $value): array
    {
        if (is_string($value)) {
            return $value === '' ? [] : [self::baseLocale() => $value];
        }

        if (! is_array($value)) {
            return [];
        }

        return array_filter(
            array_map(fn ($text) => is_scalar($text) ? (string) $text : '', $value),
            fn ($text) => $text !== '',
        );
    }

    /**
     * One translatable value as the given locale should see it.
     *
     * The chain matches App\Core\Support\HasTranslations::t() on purpose — a
     * block and a product must not disagree about what "not translated" means:
     * asked locale → base locale → first language that has anything.
     *
     * An EMPTY value in a language is therefore "not translated yet", not
     * "deliberately blank", and inherits. That is the whole inheritance rule;
     * there is no separate switch to keep in sync with it.
     */
    public static function text(mixed $value, ?string $locale = null): string
    {
        if (is_string($value)) {
            return $value;
        }

        $map = static::map($value);

        return $map[$locale ?? app()->getLocale()]
            ?? $map[self::baseLocale()]
            ?? (reset($map) ?: '');
    }

    /**
     * The settings a block VIEW gets: every translatable field already flattened
     * to the visitor's language. Block templates therefore never deal with
     * locales at all, and a block type shipped by another module becomes
     * translatable by declaring one flag — it needs no code of its own.
     *
     * @param  array<string,mixed>  $settings
     * @return array<string,mixed>
     */
    public static function localize(string $type, array $settings, ?string $locale = null): array
    {
        foreach (static::schema($type) as $field) {
            $key = $field['key'] ?? null;

            if ($key !== null && static::isTranslatable($field) && array_key_exists($key, $settings)) {
                $settings[$key] = static::text($settings[$key], $locale);
            }
        }

        return $settings;
    }

    protected static function baseLocale(): string
    {
        return content_base_locale();
    }

    /**
     * Coerces arbitrary input into exactly the shape the type declares.
     *
     * This is the security boundary, and it runs on BOTH admin paths — the
     * generated form and the raw-JSON textarea. Keys the schema does not
     * declare are dropped rather than stored; values are cast by field type;
     * a `select` may only hold one of its declared options; a field carrying
     * a `permission` is only writable by someone who has it, and otherwise
     * keeps whatever was already saved instead of being blanked by a user who
     * simply cannot see it.
     *
     * @param  array<string,mixed>  $input     untrusted
     * @param  array<string,mixed>  $existing  currently stored settings
     * @param  'form'|'json'        $mode      which editor produced $input
     * @return array<string,mixed>
     */
    public static function sanitize(string $type, array $input, array $existing = [], string $mode = 'form'): array
    {
        $clean = [];

        foreach (static::schema($type) as $field) {
            $key = $field['key'] ?? null;
            if ($key === null) {
                continue;
            }

            if (! empty($field['permission']) && ! auth()->user()?->can($field['permission'])) {
                // Preserve, don't blank: an editor without the permission is
                // still allowed to change the block's other fields.
                if (array_key_exists($key, $existing)) {
                    $clean[$key] = $existing[$key];
                }

                continue;
            }

            $clean[$key] = static::isTranslatable($field)
                ? static::castTranslatable($input[$key] ?? null, $existing[$key] ?? null, $mode)
                : static::castValue($field, $input[$key] ?? null);
        }

        return $clean;
    }

    /**
     * The two editors mean genuinely different things by the same input, so
     * this is the one place that has to know which one is talking.
     *
     * FORM: shows a single language at a time (admin_content_locale()) and posts
     * a single string, so the value is MERGED into what is stored — a plain
     * replace would wipe every other translation on every save. Blank means "not
     * translated in this language" and REMOVES that entry, letting the fallback
     * chain take over rather than storing an empty string that would shadow it.
     *
     * JSON: shows the whole stored object, so what the author writes is stored
     * verbatim — a string stays a string ("same in every language"), a map
     * replaces the map. Merging here would silently turn `"body": "b"` into
     * `{"en": …, "ru": "b"}`, i.e. produce something the author did not type
     * into an editor whose entire premise is that it shows the real object.
     *
     * @param  'form'|'json'  $mode
     * @return array<string,string>|string
     */
    protected static function castTranslatable(mixed $input, mixed $existing, string $mode = 'form'): array|string
    {
        if (is_array($input)) {
            $map = static::map($input);

            return $map === [] ? '' : $map;
        }

        if ($mode === 'json') {
            return is_scalar($input) ? trim((string) $input) : '';
        }

        $map = static::map($existing);
        $locale = function_exists('admin_content_locale') ? admin_content_locale() : self::baseLocale();
        $text = is_scalar($input) ? trim((string) $input) : '';

        if ($text === '') {
            unset($map[$locale]);
        } else {
            $map[$locale] = $text;
        }

        // A value that exists in the base language only — or in no language at
        // all — goes back to the plain string form: the map would carry no
        // information the string does not, and settings a merchant never
        // translated should not LOOK translated when they open the JSON tab.
        if ($map === []) {
            return '';
        }

        return array_keys($map) === [self::baseLocale()] ? $map[self::baseLocale()] : $map;
    }

    protected static function castValue(array $field, mixed $value): mixed
    {
        $default = $field['default'] ?? null;

        return match ($field['type'] ?? 'text') {
            'checkbox' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'number' => static::castNumber($field, $value, $default),
            'select' => static::castSelect(static::optionValues($field), $value, $default),
            'picks' => static::castPicks(static::optionValues($field), $value),
            // text/textarea/html are all strings; `html` differs only in which
            // control the form renders and in carrying a permission.
            //
            // is_scalar guard, not a plain (string) cast: the raw-JSON editor
            // can hand us an array or object here, and casting that yields the
            // literal "Array" plus a PHP warning. A non-scalar is not a
            // malformed string, it is the wrong type — fall back to the default.
            default => is_scalar($value) ? (string) $value : (string) ($default ?? ''),
        };
    }

    /** Once per field: a lazily built option list is a query. */
    protected static function castSelect(array $values, mixed $value, mixed $default): mixed
    {
        return in_array($value, $values, true) ? $value : ($default ?? ($values[0] ?? null));
    }

    /**
     * Ids in the order given, each once, only those still offered — compared
     * as strings, since a form posts "12" for the option 12.
     *
     * @return list<int|string>
     */
    protected static function castPicks(array $values, mixed $value): array
    {
        $offered = [];
        foreach ($values as $option) {
            $offered[(string) $option] = $option;
        }

        $picked = [];
        foreach (is_array($value) ? $value : [] as $id) {
            if (is_scalar($id) && isset($offered[(string) $id])) {
                $picked[(string) $id] = $offered[(string) $id];
            }
        }

        return array_values($picked);
    }

    protected static function castNumber(array $field, mixed $value, mixed $default): int|float
    {
        if (! is_numeric($value)) {
            return $default ?? 0;
        }

        $number = 0 + $value;

        if (isset($field['min'])) {
            $number = max($field['min'], $number);
        }
        if (isset($field['max'])) {
            $number = min($field['max'], $number);
        }

        return $number;
    }

    /**
     * A select's options, with a lazily built list resolved. See the class
     * docblock for why the list may be a closure.
     *
     * @return list<mixed>
     */
    public static function options(array $field): array
    {
        $options = $field['options'] ?? [];

        return array_values(is_callable($options) ? (array) $options() : (array) $options);
    }

    /** @return list<string> */
    public static function optionValues(array $field): array
    {
        return array_map(
            fn ($option) => is_array($option) ? ($option['value'] ?? '') : $option,
            static::options($field),
        );
    }

    /**
     * What a visitor calls this block — the word on its tab.
     *
     * @param  array<string, mixed>  $settings  already localised
     */
    public static function heading(string $type, array $settings): string
    {
        $resolver = static::find($type)['heading'] ?? null;

        $heading = is_callable($resolver) ? $resolver($settings) : ($settings['heading'] ?? '');

        return is_scalar($heading) ? trim((string) $heading) : '';
    }

    /** For tests and module uninstall — the registry is process-local, not persisted. */
    public static function flush(): void
    {
        static::$types = [];
    }
}
