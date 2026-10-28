<?php

namespace App\Core\Display;

/**
 * The kinds of question a display rule may ask — a registry, not a list.
 *
 * This is the third condition engine in the project and the first one meant to
 * be extended. `Torgash\Shipping\Conditions` interrogates a cart and
 * `Torgash\Automations\Conditions` a placed order: both own a closed set of
 * kinds written as class constants, because in both cases the module that asks
 * the question also owns every answer.
 *
 * Display rules are the opposite. "Show this only on wholesale products, only
 * to a logged-in customer, only where the pickup point has it in stock" is one
 * sentence whose halves belong to three different modules, and none of them may
 * know about the others. So the ENGINE is core's, and every kind is registered:
 *
 *     RuleKinds::register('customer_group', [
 *         'label'     => 'Customer group',
 *         'group'     => 'visitor',
 *         'operators' => ['in' => 'belongs to', 'not_in' => 'does not belong to'],
 *         'value'     => 'options',           // none | text | number | options | search | periods
 *         'options'   => fn () => [1 => 'Wholesale'],
 *         'matches'   => fn (array $rule, array $context) => true,
 *     ]);
 *
 * `search` is `options` for a list too long to send to the page (174 000
 * towns): `search` => fn () => the URL of a type-ahead answering
 * `{items: [{id, label}]}` for `?q=`, and `labels` => fn (array $ids) =>
 * [id => label] for the ones already chosen. `periods` is a list of
 * from–to pairs, `format` => `date` (25.12, or 25.12.2026 for one year
 * only) or `time` (18:00) — checked by `Periods`. An `options` kind may
 * give `labels` too, when its options are drawn for a picker (a tree,
 * indented): a rule's description reads the plain names from there.
 *
 * A kind about the PRODUCT may say which products it can pass, as a query
 * (`narrow` => fn (Builder $products, array $rule) => …): whoever wants an
 * example — a landing looked at before it goes live — then finds one in SQL
 * instead of asking every product in turn (`DisplayRules::narrow()`). It may
 * only narrow, never decide: `matches` is still asked, so a kind that cannot
 * say itself exactly in SQL says nothing.
 *
 * A definition is code in a file, exactly like a block type: the admin picks
 * from what is registered and fills in a value, and nothing they can type
 * becomes something that runs.
 *
 * For pages kept ready for guests (PLAN §61): a kind whose answer moves with
 * the clock says when it next may (`changes` => fn ($rule, $context) => a
 * moment), and a kind that asks something of the visitor no session shows —
 * their address, their browser — says `personal` => true, so a block it
 * guards is drawn for every visitor. A kind that reads the session (the
 * visitor's town) needs neither: the reading is seen.
 */
class RuleKinds
{
    /** How a kind asks for its value; decides the control the admin sees. */
    public const VALUE_TYPES = ['none', 'text', 'number', 'options', 'search', 'periods'];

    /** Value types that are a LIST of chosen ids. */
    public const LISTS = ['options', 'search'];

    /** @var array<string, array<string, mixed>> */
    protected static array $kinds = [];

    /** @param array<string, mixed> $definition */
    public static function register(string $name, array $definition): void
    {
        static::$kinds[$name] = $definition + [
            'label' => ['en' => $name],
            'group' => 'general',
            'operators' => [],
            'value' => 'none',
            'options' => null,
            'matches' => fn () => false,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return static::$kinds;
    }

    /** @return array<string, mixed>|null */
    public static function find(?string $kind): ?array
    {
        return $kind === null ? null : (static::$kinds[$kind] ?? null);
    }

    public static function has(string $kind): bool
    {
        return isset(static::$kinds[$kind]);
    }

    public static function label(string $kind, ?string $locale = null): string
    {
        $definition = static::find($kind);

        if (! $definition) {
            return $kind;
        }

        $locale ??= app()->getLocale();

        return label_text($definition['label'] ?? null, $locale, $kind);
    }

    public static function operatorLabel(string $kind, string $operator, ?string $locale = null): string
    {
        $labels = static::find($kind)['operators'][$operator] ?? null;

        return label_text($labels, $locale, $operator);
    }

    /**
     * The choices a kind offers, resolved now. Lazy on purpose: a kind whose
     * options are a table read (categories, customer groups) costs nothing on
     * the pages that never draw the rule editor.
     *
     * @return array<int|string, string>
     */
    public static function options(string $kind): array
    {
        $resolve = static::find($kind)['options'] ?? null;

        return is_callable($resolve) ? (array) $resolve() : [];
    }

    /**
     * Names for ids already chosen, for a kind whose choices are searched
     * rather than listed — the chips of a saved rule, its description.
     *
     * @param  list<string>  $ids
     * @return array<string, string>
     */
    public static function labels(string $kind, array $ids): array
    {
        $resolve = static::find($kind)['labels'] ?? null;

        if ($ids === [] || ! is_callable($resolve)) {
            return [];
        }

        return array_map('strval', (array) $resolve($ids));
    }

    /** Grouped for the admin's kind picker: group => [kind => label]. */
    public static function grouped(?string $locale = null): array
    {
        $grouped = [];

        foreach (static::$kinds as $name => $definition) {
            $grouped[$definition['group']][$name] = static::label($name, $locale);
        }

        ksort($grouped);

        return $grouped;
    }

    /** For tests, which register throwaway kinds and must not leak them. */
    public static function forget(string $kind): void
    {
        unset(static::$kinds[$kind]);
    }
}
