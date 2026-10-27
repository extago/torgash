<?php

namespace Torgash\Catalog\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shared code auto-generation for Feature/FeatureValue/PriceType: a blank
 * code field always resolves to a value (never stays empty) and never
 * silently collides — an admin-typed code that's taken is a hard
 * validation error instead, since renaming what someone deliberately typed
 * would be more surprising than refusing it.
 */
class Codes
{
    /**
     * The language matters: `Str::slug()` transliterates with a generic map
     * unless told which alphabet it is reading, and the generic one answers
     * `CVET` for `Цвет` where the Russian one answers `TSVET`. Both are
     * readable; only one is what a person matching columns would write.
     *
     * Answers '' for a name nothing can be made of (punctuation, or a script
     * with no transliteration at all) — the caller decides what such a row is
     * called, and AssignsCode names it after its id.
     */
    public static function fromName(string $name, ?string $language = null): string
    {
        return Str::upper(Str::slug($name, '-', $language ?? content_base_locale()));
    }

    /**
     * The first of a row's names that yields anything, base language first.
     *
     * A shop writing only Russian would otherwise get its codes from the empty
     * English column — which is how a catalogue of ITEM, ITEM-2, ITEM-3 gets
     * made, and those are codes that an import can match on but no person can
     * read.
     *
     * @param  array<string, string>  $names  locale => name
     */
    public static function fromNames(array $names): string
    {
        $base = content_base_locale();

        foreach ([$base, ...array_keys($names)] as $locale) {
            $name = trim((string) ($names[$locale] ?? ''));

            if ($name !== '' && ($code = static::fromName($name, $locale)) !== '') {
                return $code;
            }
        }

        return '';
    }

    /** Appends -2, -3, ... until free within the given table (optionally scoped, e.g. per feature_id). */
    public static function unique(string $base, string $table, ?int $ignoreId = null, array $scope = []): string
    {
        $candidate = $base;
        $n = 2;

        while (static::taken($candidate, $table, $ignoreId, $scope)) {
            $candidate = $base.'-'.$n++;
        }

        return $candidate;
    }

    protected static function taken(string $code, string $table, ?int $ignoreId, array $scope): bool
    {
        $query = DB::table($table)->where('code', $code);

        foreach ($scope as $column => $value) {
            $query->where($column, $value);
        }

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists();
    }
}
