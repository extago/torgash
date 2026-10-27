<?php

namespace App\Core\Support;

use App\Models\Language;

/**
 * The grammatical cases a language has — a property of the LANGUAGE, so it is
 * core's and is typed on the language's own form (`languages.cases`).
 *
 * Stored as a jsonb LIST of `{name, example}` (jsonb does not keep an
 * object's key order, and the order is the numbering); read as
 * `number => {name, example}`, numbered from 1 the way the language's
 * own textbooks number them: that number is what a template writes
 * (`{location:6}`), and a template is written per language, so "6" is always
 * read in the cases of the language it was written in. An empty list means the
 * language does not decline — one form, which is every language nobody filled
 * in and is always a safe answer.
 *
 * Declaring the cases is not the same as knowing the endings. The rules that
 * DERIVE a form are code, registered per language by whoever has written them
 * (`torgash.locations` for Russian and Ukrainian); a language with cases and
 * no rules simply has no derived forms, and a shop types the few it publishes
 * pages for. That split is what lets any language — however rare — get cases
 * without a line of code.
 *
 * The case names are what the shop TYPED about its own language, so they are
 * content and are printed as typed, never passed through `__()`.
 *
 * Read once per request (a scoped binding, forgotten between requests, see
 * PerRequest); a save of a language flushes it.
 */
class GrammaticalCases
{
    /**
     * What a fresh Russian or Ukrainian gets. The example is a common noun,
     * not a real town: the engine is not one country's, and the word only has
     * to show the case.
     */
    public const DEFAULTS = [
        'ru' => [
            1 => ['name' => 'Именительный', 'example' => 'город'],
            2 => ['name' => 'Родительный', 'example' => 'нет города'],
            3 => ['name' => 'Дательный', 'example' => 'к городу'],
            4 => ['name' => 'Винительный', 'example' => 'вижу город'],
            5 => ['name' => 'Творительный', 'example' => 'за городом'],
            6 => ['name' => 'Предложный', 'example' => 'в городе'],
        ],
        'uk' => [
            1 => ['name' => 'Називний', 'example' => 'місто'],
            2 => ['name' => 'Родовий', 'example' => 'немає міста'],
            3 => ['name' => 'Давальний', 'example' => 'місту'],
            4 => ['name' => 'Знахідний', 'example' => 'бачу місто'],
            5 => ['name' => 'Орудний', 'example' => 'за містом'],
            6 => ['name' => 'Місцевий', 'example' => 'у місті'],
            7 => ['name' => 'Кличний', 'example' => 'місто'],
        ],
    ];

    /** @var array<string, array<int, array{name: string, example: string}>>|null */
    protected ?array $byCode = null;

    /**
     * The cases of one language, numbered; [] when it does not decline.
     * "ru_RU" and "ru-RU" read as "ru".
     *
     * @return array<int, array{name: string, example: string}>
     */
    public function for(?string $locale): array
    {
        return $this->all()[self::language((string) $locale)] ?? [];
    }

    /** More than one form — the only languages anything declines. */
    public function declines(?string $locale): bool
    {
        return count($this->for($locale)) > 1;
    }

    /** "Предложный (в городе)" — what a field or a picker calls one case. */
    public function label(?string $locale, int $case): string
    {
        $row = $this->for($locale)[$case] ?? null;

        if (! $row) {
            return (string) $case;
        }

        return $row['example'] !== '' ? $row['name'].' ('.$row['example'].')' : $row['name'];
    }

    /** @return array<string, array<int, array{name: string, example: string}>> code => cases, declining languages only */
    public function all(): array
    {
        return $this->byCode ??= Language::query()->orderBy('sort')->get(['code', 'cases'])
            ->mapWithKeys(fn (Language $language) => [self::language($language->code) => self::normalise((array) $language->cases)])
            ->filter(fn (array $cases) => count($cases) > 1)
            ->all();
    }

    public function flush(): void
    {
        $this->byCode = null;
    }

    /**
     * Rows as the form posts them (a list of {name, example}, or already
     * numbered) into `number => {name, example}`, numbered from 1 in the order
     * given, blank rows dropped. One form is not "cases": a list of one is
     * stored as none.
     *
     * @return array<int, array{name: string, example: string}>
     */
    public static function normalise(array $rows): array
    {
        $cases = [];
        $number = 1;

        foreach (array_values($rows) as $row) {
            $name = trim((string) (is_array($row) ? ($row['name'] ?? '') : $row));

            if ($name === '') {
                continue;
            }

            $cases[$number++] = ['name' => $name, 'example' => trim((string) (is_array($row) ? ($row['example'] ?? '') : ''))];
        }

        return count($cases) > 1 ? $cases : [];
    }

    /**
     * Fill in the known defaults where a language has said nothing yet —
     * never over what a shop typed. Used by the migration that added the
     * column and by the seeder, which runs again on an existing database.
     */
    public static function seedDefaults(): void
    {
        foreach (self::DEFAULTS as $code => $cases) {
            // A LIST, numbered by position: jsonb does not keep an object's
            // key order, and the order IS the numbering.
            Language::where('code', $code)->whereNull('cases')->update(['cases' => json_encode(array_values($cases), JSON_UNESCAPED_UNICODE)]);
        }
    }

    public static function language(string $locale): string
    {
        return strtolower(strtok(str_replace('_', '-', $locale), '-') ?: $locale);
    }
}
