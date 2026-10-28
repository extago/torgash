<?php

namespace App\Core\Display;

use App\Models\CustomerGroup;
use App\Models\Language;
use App\Models\Storefront;

/**
 * The kinds core itself can answer: where the page is, which language it is in,
 * and who is looking.
 *
 * Deliberately only those. "Which product" is the catalogue's question and
 * "is it in stock at the chosen pickup point" is the warehouses module's — they
 * register their own, and a shop without those modules simply cannot ask.
 */
class CoreRuleKinds
{
    public static function register(): void
    {
        self::storefront();
        self::locale();
        self::visitor();
        self::customerGroup();
        self::route();
        self::calendar();
        self::weekday();
        self::timeOfDay();
    }

    /**
     * Days of the year on the shop's clock: the holidays every year
     * (25.12–08.01), or one year's date for a holiday that moves (Easter).
     */
    protected static function calendar(): void
    {
        RuleKinds::register('calendar', [
            'label' => 'Calendar',
            'group' => 'when',
            'value' => 'periods',
            'format' => 'date',
            'operators' => [
                'in' => 'is within',
                'not_in' => 'is outside',
            ],
            'matches' => fn (array $rule) => self::inPeriods($rule, 'date'),
            'changes' => fn () => Periods::nextChange([], 'date'),
        ]);
    }

    protected static function weekday(): void
    {
        RuleKinds::register('weekday', [
            'label' => 'Day of the week',
            'group' => 'when',
            'value' => 'options',
            'options' => fn () => [
                1 => __('Monday'), 2 => __('Tuesday'), 3 => __('Wednesday'), 4 => __('Thursday'),
                5 => __('Friday'), 6 => __('Saturday'), 7 => __('Sunday'),
            ],
            'operators' => [
                'in' => 'is one of',
                'not_in' => 'is not one of',
            ],
            'matches' => fn (array $rule) => self::inList(shop_now()->dayOfWeekIso, $rule),
            'changes' => fn () => Periods::nextChange([], 'date'),
        ]);
    }

    /** Hours of the day on the shop's clock; 22:00–02:00 runs over midnight. */
    protected static function timeOfDay(): void
    {
        RuleKinds::register('time_of_day', [
            'label' => 'Time of day',
            'group' => 'when',
            'value' => 'periods',
            'format' => 'time',
            'operators' => [
                'in' => 'is within',
                'not_in' => 'is outside',
            ],
            'matches' => fn (array $rule) => self::inPeriods($rule, 'time'),
            'changes' => fn (array $rule) => Periods::nextChange((array) ($rule['value'] ?? []), 'time'),
        ]);
    }

    /** @param array<string, mixed> $rule */
    protected static function inPeriods(array $rule, string $format): bool
    {
        $inside = Periods::contains((array) ($rule['value'] ?? []), $format);

        return ($rule['operator'] ?? 'in') === 'not_in' ? ! $inside : $inside;
    }

    protected static function storefront(): void
    {
        RuleKinds::register('storefront', [
            'label' => 'Storefront',
            'group' => 'where',
            'value' => 'options',
            'options' => fn () => Storefront::orderBy('name')->pluck('name', 'id')->all(),
            'operators' => [
                'in' => 'is one of',
                'not_in' => 'is not one of',
            ],
            'matches' => fn (array $rule, array $context) => self::inList(
                $context['storefront']?->id,
                $rule,
            ),
        ]);
    }

    protected static function locale(): void
    {
        RuleKinds::register('locale', [
            'label' => 'Language',
            'group' => 'where',
            'value' => 'options',
            'options' => fn () => Language::where('enabled', true)->orderBy('sort')->pluck('native_name', 'code')->all(),
            'operators' => [
                'in' => 'is one of',
                'not_in' => 'is not one of',
            ],
            'matches' => fn (array $rule, array $context) => self::inList($context['locale'] ?? null, $rule),
        ]);
    }

    protected static function visitor(): void
    {
        RuleKinds::register('visitor', [
            'label' => 'Visitor',
            'group' => 'who',
            'value' => 'none',
            'operators' => [
                'guest' => 'is not signed in',
                'authenticated' => 'is signed in',
            ],
            'matches' => fn (array $rule, array $context) => ($rule['operator'] ?? '') === 'guest'
                ? ! isset($context['user'])
                : isset($context['user']),
        ]);
    }

    protected static function customerGroup(): void
    {
        RuleKinds::register('customer_group', [
            'label' => 'Customer group',
            'group' => 'who',
            'value' => 'options',
            // t(), not pluck(): the group's name is translatable JSONB, and an
            // array reaching a label is a rule nobody can read.
            'options' => fn () => CustomerGroup::orderBy('id')->get()
                ->mapWithKeys(fn (CustomerGroup $group) => [$group->id => $group->t('name')])->all(),
            'operators' => [
                'in' => 'belongs to',
                'not_in' => 'does not belong to',
            ],
            // A guest belongs to no group, so "belongs to Wholesale" is a No and
            // "does not belong to Wholesale" is a Yes — which is what the words
            // say, and the reason this is not written as "the group is not X".
            'matches' => fn (array $rule, array $context) => self::inList(
                $context['user']?->customer_group_id,
                $rule,
            ),
        ]);
    }

    protected static function route(): void
    {
        RuleKinds::register('route', [
            'label' => 'Page',
            'group' => 'where',
            'value' => 'text',
            'operators' => [
                'is' => 'is the route',
                'is_not' => 'is not the route',
                'starts_with' => 'route starts with',
            ],
            'matches' => function (array $rule, array $context) {
                // Route NAMES, not paths: a name survives a slug change, a
                // locale prefix and a rename of the page's title. The locale
                // copies are named `ru.catalog.product`, so `starts_with` is the
                // operator that catches a page in every language at once — and
                // `is` is compared against the bare name too, for the same
                // reason.
                $name = (string) ($context['route'] ?? '');
                $bare = preg_replace('/^[a-z]{2}\./', '', $name);
                $wanted = trim((string) ($rule['value'] ?? ''));

                if ($wanted === '') {
                    return false;
                }

                return match ($rule['operator'] ?? '') {
                    'is' => $name === $wanted || $bare === $wanted,
                    'is_not' => $name !== $wanted && $bare !== $wanted,
                    'starts_with' => str_starts_with($name, $wanted) || str_starts_with((string) $bare, $wanted),
                    default => false,
                };
            },
        ]);
    }

    /**
     * The shape every "one of these" kind shares: a value the context may not
     * have at all, against a list the admin picked.
     *
     * A missing value is a No for `in` and a Yes for `not_in`, which is what
     * both sentences mean read aloud — a page with no storefront is not "one of
     * these two storefronts", and it is genuinely "not one of them".
     *
     * @param  array<string, mixed>  $rule
     */
    protected static function inList(mixed $value, array $rule): bool
    {
        $wanted = array_map('strval', (array) ($rule['value'] ?? []));
        $present = $value !== null && in_array((string) $value, $wanted, true);

        return ($rule['operator'] ?? 'in') === 'not_in' ? ! $present : $present;
    }
}
