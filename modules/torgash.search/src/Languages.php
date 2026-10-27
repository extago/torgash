<?php

namespace Torgash\Search;

/**
 * Which Postgres text-search configuration reduces a language's words to their
 * stems ("ноутбуки" → "ноутбук", "laptops" → "laptop").
 *
 * Only the languages Postgres ships a stemmer for; anything else — Ukrainian
 * among them, there is no `ukrainian` configuration — is indexed as written
 * and found by the start of a word, which is what a customer typing letter by
 * letter needs most anyway.
 */
class Languages
{
    public const CONFIGS = [
        'ru' => 'russian', 'en' => 'english', 'de' => 'german', 'fr' => 'french', 'es' => 'spanish',
        'it' => 'italian', 'pt' => 'portuguese', 'nl' => 'dutch', 'pl' => null, 'tr' => 'turkish',
        'ro' => 'romanian', 'sr' => 'serbian', 'lt' => 'lithuanian', 'hu' => 'hungarian', 'fi' => 'finnish',
        'sv' => 'swedish', 'da' => 'danish', 'nb' => 'norwegian', 'el' => 'greek', 'hy' => 'armenian',
    ];

    public static function stemmer(string $locale): ?string
    {
        return self::CONFIGS[$locale] ?? null;
    }

    /**
     * Every stemmer worth trying on a QUERY. A customer's language is not
     * known from what they type — "laptop" on a Russian storefront is still
     * English — so a word is stemmed by each of the shop's languages that has
     * a stemmer, and any of them may match.
     *
     * @param  list<string>  $locales
     * @return list<string>
     */
    public static function stemmers(array $locales): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($l) => self::stemmer($l), $locales))));
    }
}
