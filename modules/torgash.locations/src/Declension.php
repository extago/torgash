<?php

namespace Torgash\Locations;

use App\Core\Support\GrammaticalCases;
use Illuminate\Support\Str;

/**
 * A town's name in the case a sentence needs it in — "Киев", "Киева", "в
 * Киеве".
 *
 * Needed the moment a shop publishes a page per city (PLAN §30.9): a title
 * reading "Ноутбуки Киев" is what a template with one form can produce, and it
 * reads like a machine wrote it. A typical geo add-on ships seven columns
 * per language for this; we derive them and store only what somebody had to
 * correct, for one reason with a number on it — a pack is 192 000 Russian
 * places, and nobody fills 192 000 × 6 boxes. The shop publishes pages for a
 * dozen cities and fixes those.
 *
 * **Derived on READ, never written into the table.** The same shape as
 * `Product::resolvedWeight()`: what a person typed wins, and the rules answer
 * for everything else. A generator that wrote its guesses into 192 000 rows
 * would make every one of them look like the shop's own decision, and the next
 * improvement to these rules would reach none of them.
 *
 * The rules cover the shapes that are mechanical, and the docblocks name what
 * they get wrong. That is the honest split: the common case costs nothing, and
 * the exception is one field away on the place's own screen. Known rough edges,
 * measured against the real packs:
 *
 *  - Russian feminine `-ь` ("Тверь" → Твери, not Тверя): `-ь` is masculine in
 *    "Ставрополь" and feminine in "Тверь", and nothing in the letters says
 *    which. The masculine paradigm is used, because the masculine ones are the
 *    many.
 *  - a fleeting vowel ("Елец" → Ельца) is not modelled.
 *  - an adjectival name ("Нижний Новгород", "Рівне") is left alone rather than
 *    declined by the noun paradigm, which would be wrong in every case at once.
 *  - a name that is already indeclinable ("Сочи", "Баку", "Иваново") is
 *    correctly left alone by the same rule.
 */
class Declension
{
    /**
     * The endings this module knows, by language: a callable taking the
     * nominative and answering case => form (case 2 onwards), or null for a
     * name the rules leave alone.
     *
     * Which cases a language HAS is not decided here — that is the language's
     * own setting (core's `GrammaticalCases`, typed on the language form), so
     * any language gets cases without code. The rules are the part that has
     * to be code, and they are a registry, `locations.declension.rules`: this
     * module answers for Russian and Ukrainian, and a module that knows Polish
     * adds Polish without touching this class. A language with cases and no
     * rules derives nothing — every case reads the nominative until the shop
     * types a form, which is the honest answer.
     *
     * @var array<string, callable(string): ?array<int, string>>|null
     */
    protected ?array $rules = null;

    /**
     * The case numbers this language has, always starting at 1 — just [1] for
     * a language that does not decline.
     *
     * @return list<int>
     */
    public function cases(string $locale): array
    {
        return array_keys(app(GrammaticalCases::class)->for($locale) ?: [1 => null]);
    }

    /** What to call one case on screen — the language's own words, as the shop typed them. */
    public function label(string $locale, int $case): string
    {
        return app(GrammaticalCases::class)->label($locale, $case);
    }

    /** @return array<string, callable(string): ?array<int, string>> */
    public function rules(): array
    {
        return $this->rules ??= (array) hook_filter('locations.declension.rules', [
            'ru' => fn (string $name) => $this->russian($name),
            'uk' => fn (string $name) => $this->ukrainian($name),
        ]);
    }

    /**
     * Every form of $name in $locale, keyed by case number — case 1 is always
     * the name itself.
     *
     * @return array<int, string>
     */
    public function forms(string $name, string $locale): array
    {
        $name = trim($name);
        $language = $this->language($locale);
        $declared = app(GrammaticalCases::class)->for($language);
        $rule = $this->rules()[$language] ?? null;

        // Not declined, or declined with no rules known: one form, and form()
        // answers every case with it.
        if ($name === '' || count($declared) < 2 || ! $rule) {
            return [1 => $name];
        }

        // A name in more than one word, or one that is an adjective, is a
        // paradigm of its own ("Нижний Новгород" declines both halves, "Рівне"
        // declines like an adjective). Left as it stands: a wrong ending in
        // every case is worse than no ending at all, and these are exactly the
        // names a shop is willing to type by hand.
        if (str_contains($name, ' ') || str_contains($name, '-') || $this->isAdjectival($name, $language)) {
            return [1 => $name];
        }

        $endings = $rule($name);

        if ($endings === null) {
            return [1 => $name];
        }

        // Only the cases the language declares: a shop that listed five cases
        // is not handed a sixth because the rules happen to know one.
        return [1 => $name] + array_intersect_key($endings, $declared);
    }

    /**
     * One form, with the fallbacks that make a template safe: no such case
     * means the nominative, which is what a language with one form has.
     */
    public function form(string $name, string $locale, int $case): string
    {
        $forms = $this->forms($name, $locale);

        return $forms[$case] ?? ($forms[1] ?? $name);
    }

    /**
     * Russian, case 2 onwards, or null for a name the rules leave alone.
     *
     * @return array<int, string>|null
     */
    protected function russian(string $name): ?array
    {
        $lower = Str::lower($name);

        // -ия: Россия, Латвия. Before the bare -я rule, which would produce
        // "Росси" endings.
        if (str_ends_with($lower, 'ия')) {
            $stem = Str::substr($name, 0, -1);

            return [2 => $stem.'и', 3 => $stem.'и', 4 => $stem.'ю', 5 => $stem.'ей', 6 => $stem.'и'];
        }

        if (str_ends_with($lower, 'я')) {
            $stem = Str::substr($name, 0, -1);

            return [2 => $stem.'и', 3 => $stem.'е', 4 => $stem.'ю', 5 => $stem.'ей', 6 => $stem.'е'];
        }

        if (str_ends_with($lower, 'а')) {
            $stem = Str::substr($name, 0, -1);
            $last = Str::lower(Str::substr($stem, -1));

            return [
                // Уфы, but Калуги: after к/г/х and the hushers, -и.
                2 => $stem.(in_array($last, ['к', 'г', 'х', 'ж', 'ш', 'ч', 'щ'], true) ? 'и' : 'ы'),
                3 => $stem.'е',
                4 => $stem.'у',
                5 => $stem.(in_array($last, ['ж', 'ш', 'ч', 'щ', 'ц'], true) ? 'ей' : 'ой'),
                6 => $stem.'е',
            ];
        }

        // Sochi, Baku, Ivanovo: a name ending in a vowel other than -а/-я does
        // not decline in modern usage. "в Иванове" is defensible and "в
        // Иваново" is what people write and search for.
        if ($this->endsWithVowel($lower)) {
            return [];
        }

        // Masculine soft: Ставрополь, Гай.
        if (str_ends_with($lower, 'ь') || str_ends_with($lower, 'й')) {
            $stem = Str::substr($name, 0, -1);

            return [2 => $stem.'я', 3 => $stem.'ю', 4 => $name, 5 => $stem.'ем', 6 => $stem.'е'];
        }

        // Masculine hard, which is most of them: Курск, Харьков, Ржев.
        $last = Str::lower(Str::substr($name, -1));

        return [
            2 => $name.'а',
            3 => $name.'у',
            4 => $name,
            5 => $name.(in_array($last, ['ж', 'ш', 'ч', 'щ', 'ц'], true) ? 'ем' : 'ом'),
            6 => $name.'е',
        ];
    }

    /**
     * Ukrainian, case 2 onwards, or null for a name the rules leave alone.
     *
     * The one rule worth knowing about here is the і→о/є alternation, which is
     * not decoration: without it "Львів" declines to "Львіва" and "Київ" to
     * "Київа", and both are wrong in a way any reader sees instantly. The real
     * forms are built on a different stem — Львов-, Києв-, Харков-,
     * Тернопол- — and that stem is what the endings hang off.
     *
     * @return array<int, string>|null
     */
    protected function ukrainian(string $name): ?array
    {
        $lower = Str::lower($name);

        if (str_ends_with($lower, 'ія')) {
            $stem = Str::substr($name, 0, -1);

            return [2 => $stem.'ї', 3 => $stem.'ї', 4 => $stem.'ю', 5 => $stem.'єю', 6 => $stem.'ї', 7 => $stem.'є'];
        }

        if (str_ends_with($lower, 'я')) {
            $stem = Str::substr($name, 0, -1);

            return [2 => $stem.'і', 3 => $stem.'і', 4 => $stem.'ю', 5 => $stem.'ею', 6 => $stem.'і', 7 => $stem.'е'];
        }

        if (str_ends_with($lower, 'а')) {
            $stem = Str::substr($name, 0, -1);

            return [
                2 => $stem.'и', 3 => $stem.'і', 4 => $stem.'у',
                5 => $stem.'ою', 6 => $stem.'і', 7 => $stem.'о',
            ];
        }

        if ($this->endsWithVowel($lower)) {
            return [];
        }

        // Київ → Києв-, Львів → Львов-, Харків → Харков-.
        if (str_ends_with($lower, 'їв')) {
            return $this->ukrainianHard(Str::substr($name, 0, -2).'єв', soft: false);
        }

        if (str_ends_with($lower, 'ів')) {
            return $this->ukrainianHard(Str::substr($name, 0, -2).'ов', soft: false);
        }

        // Тернопіль → Тернопол-ь: the same alternation one letter further in.
        if (str_ends_with($lower, 'ь')) {
            return $this->ukrainianHard($this->openSyllable(Str::substr($name, 0, -1)), soft: true, nominative: $name);
        }

        return $this->ukrainianHard($this->openSyllable($name), soft: false, nominative: $name);
    }

    /**
     * The masculine endings, off a stem the alternation has already fixed.
     *
     * @return array<int, string>
     */
    protected function ukrainianHard(string $stem, bool $soft, ?string $nominative = null): array
    {
        $last = Str::lower(Str::substr($stem, -1));
        $velar = in_array($last, ['к', 'г', 'х'], true);

        return [
            2 => $stem.($soft ? 'я' : 'а'),
            3 => $stem.($soft ? 'ю' : 'у'),
            4 => $nominative ?? $stem,
            5 => $stem.($soft ? 'ем' : 'ом'),
            // у Луцьку, but у Києві: a velar stem takes -у, everything else -і.
            6 => $stem.($velar ? 'у' : 'і'),
            7 => $stem.($soft || $velar ? 'у' : 'е'),
        ];
    }

    /**
     * The і of a closed syllable becomes о when the syllable opens —
     * Тернопіль → Тернопол-, Ужгород unchanged.
     *
     * Only the LAST і, and only when it is the last vowel of the word: that is
     * where the alternation happens, and touching an earlier one would rewrite
     * the middle of a name ("Івано" is not "Овано").
     */
    protected function openSyllable(string $stem): string
    {
        $length = Str::length($stem);

        for ($i = $length - 1; $i >= 0; $i--) {
            $letter = Str::substr($stem, $i, 1);

            if (! $this->isVowel(Str::lower($letter))) {
                continue;
            }

            // The first vowel found from the right is the one that alternates,
            // and only if it is і — and never if it is the whole word.
            if (Str::lower($letter) === 'і' && $i > 0) {
                return Str::substr($stem, 0, $i).'о'.Str::substr($stem, $i + 1);
            }

            return $stem;
        }

        return $stem;
    }

    /** An adjectival name declines like an adjective, which these rules do not do. */
    protected function isAdjectival(string $name, string $language): bool
    {
        $lower = Str::lower($name);

        return $language === 'ru'
            ? str_ends_with($lower, 'ий') || str_ends_with($lower, 'ый') || str_ends_with($lower, 'ая')
            : str_ends_with($lower, 'ий') || str_ends_with($lower, 'ій') || str_ends_with($lower, 'не');
    }

    protected function endsWithVowel(string $lower): bool
    {
        return $this->isVowel(Str::substr($lower, -1));
    }

    protected function isVowel(string $letter): bool
    {
        return in_array($letter, [
            'а', 'е', 'ё', 'є', 'и', 'і', 'ї', 'о', 'у', 'ы', 'э', 'ю', 'я',
        ], true);
    }

    /** `ru-RU`, `ru_RU` and `ru` are one language as far as grammar is concerned. */
    protected function language(string $locale): string
    {
        return Str::lower(Str::before(str_replace('_', '-', $locale), '-'));
    }
}
