<?php

namespace Torgash\Exim\Import;

use Torgash\Exim\ImportRunner;
use Torgash\Exim\Models\ImportPreset;
use Torgash\Exim\SchemaRegistry;

/**
 * Which column of a file is which field (PLAN §58) — the step the importer
 * used to skip: a header it did not recognise was silently dropped, so a
 * file from another engine imported nothing and said nothing.
 *
 * A mapping is a list aligned with the file's columns: a field key, or "" to
 * skip the column. Guessed, in this order, from
 *   1. a saved preset for this header (`ImportPreset::forHeader`);
 *   2. our own spellings — the field's key, its English label, its title in
 *      the admin's language ("Название (RU)"), which is what our export writes;
 *   3. the spellings other systems use, declared by the field itself as
 *      `aliases` ("Product code") and `alias_patterns` (
 *      "VAR Feature 2 [Цвет]") — the module that owns a field knows what
 *      other systems call it, exim does not.
 *
 * A field declared `per_column` can take any number of columns — "a
 * characteristic named by the column" — and its row key carries the column's
 * name: `feature_column@Цвет`. Everything else is one column per field; when a
 * file has two, the second wins.
 *
 * A translatable field is one column per language (`name_ru`); a column may
 * also fill EVERY language of it at once — `name_*` — for a file written in
 * one language whose shop wants the text everywhere until it is translated.
 *
 * A column may carry a FORMULA (`Formula`) that changes
 * its cell on the way in. Formulas are kept by column INDEX in a draft and by
 * column NAME in a preset — the next file of the same supplier may have its
 * columns in another order.
 */
class Columns
{
    public const PARAM = '@';

    /** The language part of a key that fills all of a translatable field's languages. */
    public const ALL = '*';

    /**
     * The import columns a mapping key writes: itself, or — for `name_*` —
     * every language column of `name`. Empty for a key nobody declares.
     *
     * @param  array<string, array>  $fieldDefs  keyed by key
     * @return list<string>
     */
    public static function targets(string $key, array $fieldDefs): array
    {
        if (isset($fieldDefs[$key])) {
            return [$key];
        }

        if (! str_ends_with($key, '_'.self::ALL)) {
            return [];
        }

        $base = substr($key, 0, -strlen('_'.self::ALL));

        return array_keys(array_filter($fieldDefs, fn (array $field) => ($field['base_key'] ?? null) === $base));
    }

    /**
     * @param  list<string>  $header
     * @param  list<array>  $fieldDefs  SchemaRegistry::fieldsFor()
     * @return list<string>
     */
    public static function guess(array $header, array $fieldDefs, ?ImportPreset $preset = null): array
    {
        $saved = [];
        foreach ((array) ($preset?->mapping ?? []) as $column => $key) {
            $saved[self::normal((string) $column)] = (string) $key;
        }

        $known = collect($fieldDefs)->keyBy('key');
        $byKey = $known->all();
        $spellings = [];

        // Later spellings never overwrite earlier ones: a key or a label is
        // ours and beats somebody else's alias.
        foreach ($fieldDefs as $field) {
            foreach ([$field['key'], $field['label'], SchemaRegistry::title($field)] as $spelling) {
                $spellings[self::normal($spelling)] ??= $field['key'];
            }
        }
        foreach ($fieldDefs as $field) {
            foreach ((array) ($field['aliases'] ?? []) as $alias) {
                $spellings[self::normal($alias)] ??= $field['key'];
            }
        }

        $mapping = [];
        $taken = [];

        foreach ($header as $i => $column) {
            $normal = self::normal((string) $column);
            $key = '';

            if (array_key_exists($normal, $saved) && ($saved[$normal] === '' || self::targets($saved[$normal], $byKey) !== [])) {
                $key = $saved[$normal];
            } elseif (isset($spellings[$normal])) {
                $key = $spellings[$normal];
            } else {
                foreach ($fieldDefs as $field) {
                    foreach ((array) ($field['alias_patterns'] ?? []) as $pattern) {
                        if (@preg_match($pattern, (string) $column)) {
                            $key = $field['key'];
                            break 2;
                        }
                    }
                }
            }

            // A guess never maps two columns onto one field — the admin may.
            if ($key !== '' && ! ($known->get($key)['per_column'] ?? false) && isset($taken[$key]) && ! array_key_exists($normal, $saved)) {
                $key = '';
            }

            $taken[$key] = true;
            $mapping[$i] = $key;
        }

        return $mapping;
    }

    /**
     * The formulas that can be read, by column index; a broken one is left
     * out (the screen refuses to check an import while one is broken).
     *
     * @param  array<int|string, string>  $texts
     * @return array<int, Formula>
     */
    public static function formulas(array $texts): array
    {
        $formulas = [];

        foreach ($texts as $i => $text) {
            if (trim((string) $text) === '') {
                continue;
            }
            try {
                $formulas[(int) $i] = Formula::parse((string) $text);
            } catch (FormulaException) {
                // Not read, not applied.
            }
        }

        return $formulas;
    }

    /**
     * A preset's formulas (by column name) as this header's (by index).
     *
     * @param  list<string>  $header
     * @return array<int, string>
     */
    public static function formulasByIndex(array $header, array $byName): array
    {
        $wanted = [];
        foreach ($byName as $column => $text) {
            $wanted[self::normal((string) $column)] = (string) $text;
        }

        $formulas = [];
        foreach ($header as $i => $column) {
            if (($text = $wanted[self::normal((string) $column)] ?? '') !== '') {
                $formulas[$i] = $text;
            }
        }

        return $formulas;
    }

    /**
     * This header's formulas (by index) as a preset keeps them (by name).
     *
     * @param  list<string>  $header
     * @return array<string, string>
     */
    public static function formulasByName(array $header, array $byIndex): array
    {
        $formulas = [];
        foreach ($byIndex as $i => $text) {
            if (trim((string) $text) !== '' && isset($header[$i])) {
                $formulas[(string) $header[$i]] = (string) $text;
            }
        }

        return $formulas;
    }

    /**
     * One file line as the row the importer writes: field key => cell.
     *
     * A cell whose formula cannot be worked out for this line is left out of
     * the row — the field keeps what it had — and `$problem` is told why:
     * importing the bare purchase price where a marked-up one was meant is
     * worse than not touching the price.
     *
     * @param  list<string>  $header
     * @param  list<string>  $mapping
     * @param  list<string>  $cells
     * @param  array<string, array>  $fieldDefs  keyed by key
     * @param  array<int, Formula>  $formulas  by column index (`formulas()`)
     * @param  ?callable(string, FormulaException): void  $problem  told the column's name and why
     */
    public static function row(array $header, array $mapping, array $cells, array $fieldDefs, array $formulas = [], ?callable $problem = null): array
    {
        $row = [];
        $byName = null;

        foreach ($mapping as $i => $key) {
            if ($key === '' || $key === null) {
                continue;
            }

            $value = ImportRunner::unguard((string) ($cells[$i] ?? ''));

            if (isset($formulas[$i])) {
                $byName ??= self::byName($header, $cells);
                try {
                    $value = $formulas[$i]->apply($value, $byName);
                } catch (FormulaException $e) {
                    $problem && $problem((string) ($header[$i] ?? $i), $e);

                    continue;
                }
            }

            foreach (self::targets((string) $key, $fieldDefs) as $target) {
                if ($fieldDefs[$target]['per_column'] ?? false) {
                    $row[$target.self::PARAM.trim((string) ($header[$i] ?? $i))] = $value;
                } else {
                    $row[$target] = $value;
                }
            }
        }

        return $row;
    }

    /**
     * The line by column name, for a formula's `col()`; of two columns with
     * one name, the first.
     *
     * @return array<string, string>
     */
    public static function byName(array $header, array $cells): array
    {
        $row = [];
        foreach ($header as $i => $column) {
            $row[(string) $column] ??= ImportRunner::unguard((string) ($cells[$i] ?? ''));
        }

        return $row;
    }

    /** `feature_column@Цвет` => ['feature_column', 'Цвет']; anything else => [key, null]. */
    public static function split(string $rowKey, array $fieldDefs): array
    {
        if (isset($fieldDefs[$rowKey]) || ! str_contains($rowKey, self::PARAM)) {
            return [$rowKey, null];
        }

        [$key, $param] = explode(self::PARAM, $rowKey, 2);

        return [$key, $param];
    }

    public static function normal(string $spelling): string
    {
        return mb_strtolower(trim(preg_replace('/^\x{FEFF}/u', '', $spelling) ?? $spelling));
    }
}
