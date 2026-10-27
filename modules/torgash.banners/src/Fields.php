<?php

namespace Torgash\Banners;

use Illuminate\Support\Facades\Blade;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Torgash\Media\Gallery;

/**
 * The fields of a template (PLAN §51): what a {token} in its HTML is. The
 * template's author picks a TYPE for each token they wrote; the banner's form
 * draws a control for it, the value is accepted only in that type's shape,
 * and the token prints it escaped for wherever the author put it — a text, an
 * attribute, a `style`.
 *
 * Nothing a banner's editor types reaches the page as markup except a `rich`
 * text, and that only through RichLine's few tags.
 */
class Fields
{
    public const TYPES = ['text', 'rich', 'image', 'link', 'color', 'select', 'checkbox', 'icon', 'links', 'video'];

    /** A video behind a slide: short and looping, so a cap well below a film's. */
    public const VIDEO_MAX_MB = 50;

    /** What a `video` field takes, by the file's own first bytes (as torgash.video judges its files). */
    public const VIDEO_TYPES = ['video/mp4' => 'mp4', 'video/webm' => 'webm'];

    /** How many links a `links` field keeps — chips on a card, not a menu. */
    public const LINKS_MAX = 12;

    /** The types a translation is kept for: words, in the language being edited. */
    public const TRANSLATABLE = ['text', 'rich'];

    /** Token names a template may not use for a field — the engine fills them. */
    public const ENGINE = [
        'banner' => ['loading'],
        'frame' => ['slides', 'items', 'dots', 'prev', 'next', 'counter', 'progress', 'aside'],
    ];

    public const KEY_PATTERN = '/^[a-z][a-z0-9_-]{0,63}$/';

    /** A picture field's media collection on the record. */
    public static function collection(string $key): string
    {
        return 'f_'.$key;
    }

    /**
     * A field as declared, made whole: a known type, a label, a default in
     * the type's shape, options for a choice, the word a switch prints.
     *
     * @return array{key: string, type: string, label: string, help: string, default: mixed, options: list<array{value: string, label: string}>, on: string}|null
     */
    public static function shape(mixed $field): ?array
    {
        if (! is_array($field) || ! preg_match(self::KEY_PATTERN, (string) ($field['key'] ?? ''))) {
            return null;
        }

        $type = in_array($field['type'] ?? '', self::TYPES, true) ? $field['type'] : 'text';
        $options = [];

        foreach ((array) ($field['options'] ?? []) as $value => $option) {
            // Either [{value, label}] or {value: label}.
            [$optionValue, $optionLabel] = is_array($option)
                ? [(string) ($option['value'] ?? ''), (string) ($option['label'] ?? $option['value'] ?? '')]
                : [(string) $value, (string) $option];

            if ($optionValue !== '') {
                $options[] = ['value' => $optionValue, 'label' => $optionLabel !== '' ? $optionLabel : $optionValue];
            }
        }

        $shaped = [
            'key' => (string) $field['key'],
            'type' => $type,
            'label' => (string) ($field['label'] ?? '') ?: (string) $field['key'],
            'help' => (string) ($field['help'] ?? ''),
            'default' => null,
            'options' => $options,
            'on' => (string) ($field['on'] ?? '') ?: (string) $field['key'],
            // A picture with none of its own shows this other picture field's —
            // "for a phone" falls back to the main one, so a <source> is never empty.
            'fallback' => $type === 'image' && preg_match(self::KEY_PATTERN, (string) ($field['fallback'] ?? '')) ? (string) $field['fallback'] : '',
        ];

        $shaped['default'] = self::accept($shaped, $field['default'] ?? null) ?? match ($type) {
            'color' => '#ffffff',
            'select' => $options[0]['value'] ?? '',
            'checkbox' => false,
            'links' => [],
            default => '',
        };

        return $shaped;
    }

    /**
     * A value as posted, in the type's shape — or null, which means "not a
     * value of this field" (the default stands). Words are taken as they come;
     * a translatable one is merged into its map by the caller.
     */
    public static function accept(array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'text' => is_scalar($value) ? mb_substr(trim((string) $value), 0, 1000) : null,
            'rich' => is_scalar($value) ? RichLine::clean(mb_substr((string) $value, 0, 5000)) : null,
            'link' => is_scalar($value) && (trim((string) $value) === '' || preg_match('#^(https?://|/(?!/))#i', trim((string) $value))) ? mb_substr(trim((string) $value), 0, 1000) : null,
            'color' => is_string($value) && preg_match('/^#[0-9a-f]{6}$/i', $value) ? strtolower($value) : null,
            'select' => is_scalar($value) && in_array((string) $value, array_column($field['options'], 'value'), true) ? (string) $value : null,
            'checkbox' => $value === null ? null : filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'icon' => is_scalar($value) && (trim((string) $value) === '' || preg_match('/^[a-z0-9-]{1,64}$/', trim((string) $value))) ? trim((string) $value) : null,
            'links' => is_array($value) ? self::links($value) : null,
            default => null,
        };
    }

    /**
     * A `links` field's rows as typed — `[{text, link}]`, the text as typed in
     * one language — kept while the link is an address. An empty text is kept
     * too: in another language's form a row may have no text in THIS one, and
     * dropping it would take the other languages' text with it. The caller
     * merges the texts into their translations by row (`mergeLinks`).
     *
     * @return list<array{text: string, link: string}>
     */
    public static function links(array $rows): array
    {
        $kept = [];

        foreach ($rows as $row) {
            $text = is_array($row) && is_scalar($row['text'] ?? null) ? mb_substr(trim((string) $row['text']), 0, 100) : '';
            $link = is_array($row) && is_scalar($row['link'] ?? null) ? mb_substr(trim((string) $row['link']), 0, 1000) : '';

            if (preg_match('#^(https?://|/(?!/))#i', $link)) {
                $kept[] = ['text' => $text, 'link' => $link];
            }
        }

        return array_slice($kept, 0, self::LINKS_MAX);
    }

    /**
     * Typed rows over stored ones, row by row: this language's text replaces
     * this language's, the others stay — the same rule as every translatable
     * word (`put_translation`).
     *
     * @param  list<array{text: string, link: string}>  $typed
     * @return list<array{text: array<string, string>, link: string}>
     */
    public static function mergeLinks(mixed $stored, array $typed): array
    {
        $stored = array_values(array_filter((array) $stored, 'is_array'));
        $merged = [];

        foreach ($typed as $i => $row) {
            $previous = $stored[$i]['text'] ?? null;
            $text = put_translation(is_array($previous) ? $previous : null, $row['text']);

            // A row with no text in any language is no link.
            if ($text !== []) {
                $merged[] = ['text' => $text, 'link' => $row['link']];
            }
        }

        return $merged;
    }

    /**
     * What a token prints for this record. Escaped for anywhere in HTML; a
     * rich text keeps its few tags; a picture is its address (or, with
     * `|width` / `|height`, its size); an icon is the engine's own <svg>.
     *
     * @param  array<string, mixed>  $values  the record's stored values
     */
    public static function render(array $field, array $values, ?HasMedia $record, string $modifiers = ''): string
    {
        $stored = $values[$field['key']] ?? null;
        $mods = array_values(array_filter(explode('|', strtolower($modifiers))));

        switch ($field['type']) {
            case 'text':
                $text = is_array($stored) ? (string) pick_translation($stored) : (string) ($stored ?? $field['default']);

                return e($text);

            case 'rich':
                $html = is_array($stored) ? (string) pick_translation($stored) : (string) ($stored ?? '');

                return nl2br($html, false);

            case 'video':
                $media = $record?->getFirstMedia(self::collection($field['key']));

                return $media ? e($media->getUrl()) : '';

            case 'image':
                $media = $record?->getFirstMedia(self::collection($field['key']))
                    ?? ($field['fallback'] !== '' ? $record?->getFirstMedia(self::collection($field['fallback'])) : null);

                return $media ? e(self::image($media, $mods)) : '';

            case 'link':
                $link = trim((string) ($stored ?? ''));

                return preg_match('#^(https?://|/(?!/))#i', $link) ? e($link) : '';

            case 'color':
                return (string) (self::accept($field, $stored) ?? $field['default']);

            case 'select':
                return e((string) (self::accept($field, $stored) ?? $field['default']));

            case 'checkbox':
                return (self::accept($field, $stored) ?? $field['default']) ? e($field['on']) : '';

            case 'links':
                // One <a> after another: the template's wrapper styles them
                // (`.chips > a`) — a list has no tokens of its own to place.
                $out = '';

                foreach (array_filter((array) $stored, 'is_array') as $row) {
                    $text = trim((string) pick_translation($row['text'] ?? null));
                    $link = trim((string) ($row['link'] ?? ''));

                    if ($text !== '' && preg_match('#^(https?://|/(?!/))#i', $link)) {
                        $out .= '<a href="'.e($link).'">'.e($text).'</a>';
                    }
                }

                return $out;

            case 'icon':
                $name = (string) (self::accept($field, $stored) ?? '');

                // An unknown name must not throw on a storefront page: it is simply not drawn.
                if ($name === '' || ! is_file(resource_path('icons/lucide/'.$name.'.svg'))) {
                    return '';
                }

                return Blade::render('<x-icon :name="$name" size="xl" />', ['name' => $name]);
        }

        return '';
    }

    /**
     * A picture's address at the size a modifier asks for — `|small` a
     * thumbnail, the default the largest generated size or the original — or
     * its own `|width` / `|height` for the <img>, so the page does not jump.
     *
     * @param  list<string>  $mods
     */
    protected static function image(Media $media, array $mods): string
    {
        if (in_array('width', $mods, true) || in_array('height', $mods, true)) {
            // Never empty for a picture that exists: inside […] an empty size
            // would take the picture away with it.
            return (string) ((int) $media->getCustomProperty(in_array('width', $mods, true) ? 'width' : 'height') ?: 'auto');
        }

        return Gallery::thumbUrl($media, in_array('small', $mods, true) ? 400 : 1200);
    }

    /**
     * What a video file is, by its first bytes — an ISO media file (`ftyp`)
     * or Matroska/WebM — never by its name. Null: not a video this takes.
     */
    public static function videoType(string $path): ?string
    {
        $head = (string) @file_get_contents($path, false, null, 0, 12);

        return match (true) {
            strlen($head) >= 8 && substr($head, 4, 4) === 'ftyp' => 'video/mp4',
            str_starts_with($head, "\x1A\x45\xDF\xA3") => 'video/webm',
            default => null,
        };
    }

    /**
     * The largest video file a form can send: the cap, or less where the
     * server takes less in one request, or the media library keeps less
     * (`media-library.max_file_size`, 10 MB unless the shop changed it).
     */
    public static function videoMaxBytes(): int
    {
        $limits = [self::VIDEO_MAX_MB * 1024 * 1024];

        if ((int) config('media-library.max_file_size') > 0) {
            $limits[] = (int) config('media-library.max_file_size');
        }

        foreach (['upload_max_filesize', 'post_max_size'] as $ini) {
            $value = trim((string) ini_get($ini));
            $number = (float) $value;
            $bytes = (int) match (strtolower(substr($value, -1))) {
                'g' => $number * 1024 ** 3,
                'm' => $number * 1024 ** 2,
                'k' => $number * 1024,
                default => $number,
            };

            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    /** "value | Label" lines, as the template form types a choice's options. */
    public static function optionsFromText(string $text): array
    {
        $options = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            [$value, $label] = array_pad(array_map('trim', explode('|', $line, 2)), 2, '');

            if ($value !== '') {
                $options[] = ['value' => $value, 'label' => $label !== '' ? $label : $value];
            }
        }

        return $options;
    }
}
