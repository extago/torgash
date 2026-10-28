<?php

namespace App\Core\Display;

/**
 * "Should this be shown here, now, to them?" — asked of a list of rules.
 *
 * Whoever owns a thing that can be hidden (a block today; a menu item, a
 * banner, a promo strip later) stores rules and calls passes(). It knows
 * nothing about what the rules ask, and the kinds know nothing about what they
 * are hiding.
 *
 * Three decisions worth stating, because each is a way this could quietly do
 * the wrong thing:
 *
 * 1. **No rules means shown.** A thing nobody put conditions on is not
 *    conditional.
 * 2. **An unknown kind fails.** A rule whose module is gone is not "ignore me"
 *    — a block reading "wholesale customers only" must not become visible to
 *    everyone because the module that knew what wholesale means was switched
 *    off. Fail closed, and say so on the admin screen.
 * 3. **A rule that cannot be evaluated fails too.** "Product is in category X"
 *    asked on the home page has no product to look at; that is a No, not a Yes.
 *    Kinds express this by returning false when their part of the context is
 *    missing, which is why `matches` is handed the whole context rather than a
 *    pre-extracted value.
 */
class DisplayRules
{
    public const MATCH_ALL = 'all';

    public const MATCH_ANY = 'any';

    /**
     * @param  list<array<string, mixed>>  $rules  each ['kind', 'operator', 'value']
     * @param  array<string, mixed>  $context  extras from the caller (e.g. product)
     */
    public function passes(array $rules, array $context = [], string $match = self::MATCH_ALL): bool
    {
        $rules = array_values(array_filter($rules, fn ($rule) => is_array($rule) && ! empty($rule['kind'])));

        if ($rules === []) {
            return true;
        }

        $context = $this->context($context);

        foreach ($rules as $rule) {
            $passed = $this->matches($rule, $context);

            // Short-circuit both ways: ALL fails on the first No, ANY passes on
            // the first Yes. A kind may cost a query, so not asking is the point.
            if ($match === self::MATCH_ANY && $passed) {
                return true;
            }

            if ($match !== self::MATCH_ANY && ! $passed) {
                return false;
            }
        }

        return $match !== self::MATCH_ANY;
    }

    /** @param array<string, mixed> $rule */
    public function matches(array $rule, array $context): bool
    {
        $definition = RuleKinds::find($rule['kind'] ?? null);

        if (! $definition) {
            return false;
        }

        // A kind whose answer moves with the clock says when it next may: a
        // page kept ready for guests is kept only until then (PLAN §61).
        if (isset($definition['changes']) && page_cache()->current() !== null) {
            page_cache()->until(($definition['changes'])($rule, $context));
        }

        return (bool) ($definition['matches'])($rule, $context);
    }

    /**
     * What every kind can count on being there, plus whatever the caller adds.
     *
     * Assembled here rather than by each caller: "which storefront, which
     * language, who is looking" is the same question on every page, and a
     * caller that forgot one would make its rules quietly unanswerable.
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    public function context(array $extra = []): array
    {
        return $extra + [
            'storefront' => current_storefront(),
            'locale' => app()->getLocale(),
            'user' => auth()->user(),
            'route' => request()?->route()?->getName(),
        ];
    }

    /**
     * Posted rows reduced to what the registry recognises.
     *
     * A kind nobody registered, an operator that kind does not offer, or a row
     * with nothing chosen where a value is required is DROPPED rather than
     * stored: such a rule fails closed for ever (see the class docblock), and a
     * half-filled row in a form must not silently hide the thing it was added
     * to. Beside the engine rather than in whichever module posts the form —
     * the rule about what a storable rule IS can only have one author.
     *
     * @param  mixed  $posted  the `rules` input, as it arrives
     * @return list<array<string, mixed>>|null  null when nothing survived
     */
    public function normalise(mixed $posted): ?array
    {
        $rules = [];

        foreach ((array) $posted as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kind = (string) ($row['kind'] ?? '');
            $definition = RuleKinds::find($kind);
            $operator = (string) ($row['operator'] ?? '');

            if (! $definition || ! isset($definition['operators'][$operator])) {
                continue;
            }

            $expects = $definition['value'] ?? 'none';
            $value = match (true) {
                in_array($expects, RuleKinds::LISTS, true) => array_values(array_unique(array_filter(array_map('strval', (array) ($row['value'] ?? [])), fn ($v) => $v !== ''))),
                $expects === 'periods' => Periods::normalise($row['value'] ?? [], (string) ($definition['format'] ?? 'date')),
                default => is_scalar($row['value'] ?? null) ? trim((string) $row['value']) : '',
            };

            if ($expects !== 'none' && ($value === [] || $value === '')) {
                continue;
            }

            $rules[] = ['kind' => $kind, 'operator' => $operator, 'value' => $expects === 'none' ? null : $value];
        }

        return $rules ?: null;
    }

    /**
     * One rule in the admin's words — "Customer group — belongs to Wholesale".
     * A kind nobody registered says so instead of printing its raw name, since
     * that is the case where the rule is also silently hiding the thing.
     *
     * @param  array<string, mixed>  $rule
     */
    public function describe(array $rule): string
    {
        $kind = (string) ($rule['kind'] ?? '');

        if (! RuleKinds::has($kind)) {
            return __('Unknown condition ":kind" — nothing is shown while it is here.', ['kind' => $kind]);
        }

        $words = RuleKinds::label($kind).' — '.RuleKinds::operatorLabel($kind, (string) ($rule['operator'] ?? ''));
        $value = $this->describeValue($kind, $rule['value'] ?? null);

        return trim($words.' '.$value);
    }

    protected function describeValue(string $kind, mixed $value): string
    {
        $definition = RuleKinds::find($kind);

        if (($definition['value'] ?? 'none') === 'none' || $value === null || $value === '' || $value === []) {
            return '';
        }

        if (($definition['value'] ?? '') === 'periods') {
            return Periods::describe($value);
        }

        if (! in_array($definition['value'] ?? '', RuleKinds::LISTS, true)) {
            return (string) (is_array($value) ? implode(', ', $value) : $value);
        }

        // Names, not ids: "belongs to 2, 5" is not a sentence anybody can check.
        $options = $definition['value'] === 'search'
            ? RuleKinds::labels($kind, array_map('strval', (array) $value))
            : RuleKinds::options($kind);
        $chosen = array_map(
            fn ($id) => (string) ($options[$id] ?? $id),
            is_array($value) ? $value : [$value],
        );

        return implode(', ', $chosen);
    }
}
