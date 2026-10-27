<?php

namespace Torgash\Shipping\Conditions;

use Torgash\Shipping\Models\MethodCondition;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Does this cart meet what the method asks of it?
 *
 * ALL conditions must pass; "one of these" lives inside a single row's value.
 * That is how merchants write the rule — "for furniture, for wholesale
 * customers, over 500" is three requirements — and it means an admin adding a
 * row can only ever narrow, never accidentally widen, what is offered.
 */
class Conditions
{
    /** kind => its heading, an English source string for the UI. */
    public const KIND_LABELS = [
        'category' => 'Category',
        'attribute' => 'Characteristic',
        'customer_group' => 'Customer group',
        'coupon' => 'Coupon',
        'cart_total' => 'Order total',
        'weight_known' => 'Weight is known',
    ];

    /** kind => the operators it allows, each an English source string for the UI. */
    public const KINDS = [
        'category' => [
            'has_any' => 'the cart contains something from',
            'all_from' => 'every product is from',
            'has_none' => 'the cart contains nothing from',
        ],
        'attribute' => [
            'attr_exists' => 'every product has the characteristic',
            'attr_missing' => 'some product lacks the characteristic',
            'attr_equals' => 'every product has the characteristic equal to',
        ],
        'customer_group' => [
            'group_in' => 'the customer belongs to',
            'group_not_in' => 'the customer does not belong to',
        ],
        'coupon' => [
            'coupon_any' => 'any coupon is applied',
            'coupon_is' => 'this coupon is applied',
            'coupon_none' => 'no coupon is applied',
        ],
        'cart_total' => [
            'total_min' => 'the order is at least',
            'total_max' => 'the order is at most',
        ],
        'weight_known' => [
            'weight_known' => 'every product has a weight',
            'weight_unknown' => 'some product has no weight',
        ],
    ];

    /**
     * One condition in plain words, for the admin — "Order total — the order
     * is at least 500.00", "Category — the cart contains something from
     * (2 categories)". So a method that "vanished" from checkout is visibly a
     * method with requirements, not a bug.
     *
     * @param  array<string, array<int, string>>  $names  kind => (id => label),
     *     for category / customer_group rows when the caller has them; a plain
     *     count is used otherwise.
     */
    public static function describe(MethodCondition $condition, array $names = []): string
    {
        $kind = __(self::KIND_LABELS[$condition->kind] ?? $condition->kind);
        $operator = __(self::KINDS[$condition->kind][$condition->operator] ?? $condition->operator);

        $value = match ($condition->kind) {
            'category', 'customer_group' => self::nameList($condition->ids(), $names[$condition->kind] ?? []),
            'attribute' => trim($condition->text('key').(
                $condition->operator === 'attr_equals' ? ' = '.$condition->text('match') : ''
            )),
            'coupon' => $condition->operator === 'coupon_is' ? $condition->text('code') : '',
            // Same rule as everywhere in the admin: the reader's chosen currency,
            // not the storefront session the admin happens to share with the shop.
            'cart_total' => $condition->amount() ? admin_price($condition->amount()) : '',
            default => '',
        };

        return trim($kind.' — '.$operator.($value === '' ? '' : ' '.$value));
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, string>  $names
     */
    protected static function nameList(array $ids, array $names): string
    {
        if ($ids === []) {
            return '';
        }

        $known = array_filter(array_map(fn ($id) => $names[$id] ?? null, $ids));

        return $known !== []
            ? implode(', ', $known)
            : __(':count selected', ['count' => count($ids)]);
    }

    /**
     * @param  iterable<MethodCondition>|null  $conditions  already loaded, to
     *     keep this out of the per-method query budget
     */
    public function pass(ShippingMethod $method, CartFacts $facts, ?iterable $conditions = null): bool
    {
        $conditions = $conditions ?? $method->conditions;

        foreach ($conditions as $condition) {
            // Conditions that cannot be evaluated are not conditions that
            // passed: a method restricted to wholesale customers must not
            // appear on a screen that has no idea who is looking.
            if (! $facts->known || ! $this->passes($condition, $facts)) {
                return false;
            }
        }

        return true;
    }

    protected function passes(MethodCondition $condition, CartFacts $facts): bool
    {
        return match ($condition->operator) {
            'has_any' => (bool) array_intersect($this->allCategories($facts), $condition->ids()),
            'has_none' => ! array_intersect($this->allCategories($facts), $condition->ids()),
            'all_from' => $facts->categoryLines !== [] && $this->everyLine(
                $facts->categoryLines,
                fn (array $categories) => (bool) array_intersect($categories, $condition->ids()),
            ),

            'attr_exists' => $facts->featureLines !== [] && $this->everyLine(
                $facts->featureLines,
                fn (array $features) => $this->feature($features, $condition->text('key')) !== null,
            ),
            'attr_missing' => ! $this->everyLine(
                $facts->featureLines,
                fn (array $features) => $this->feature($features, $condition->text('key')) !== null,
            ),
            'attr_equals' => $facts->featureLines !== [] && $this->everyLine(
                $facts->featureLines,
                fn (array $features) => $this->same(
                    $this->feature($features, $condition->text('key')),
                    $condition->text('match'),
                ),
            ),

            'group_in' => $facts->customerGroupId !== null
                && in_array($facts->customerGroupId, $condition->ids(), true),
            'group_not_in' => $facts->customerGroupId === null
                || ! in_array($facts->customerGroupId, $condition->ids(), true),

            'coupon_any' => $facts->couponCode !== null && $facts->couponCode !== '',
            'coupon_none' => $facts->couponCode === null || $facts->couponCode === '',
            'coupon_is' => $this->same($facts->couponCode, $condition->text('code')),

            'total_min' => $facts->subtotal >= $condition->amount(),
            'total_max' => $facts->subtotal <= $condition->amount(),

            'weight_known' => $facts->weightKnown,
            'weight_unknown' => ! $facts->weightKnown,

            // An operator this version does not know is a rule nobody can vouch
            // for — most likely a module downgrade. Refusing to offer is the
            // safe reading of "I do not understand the requirement".
            default => false,
        };
    }

    /** @return list<int> */
    protected function allCategories(CartFacts $facts): array
    {
        return array_values(array_unique(array_merge(...array_values($facts->categoryLines) ?: [[]])));
    }

    /**
     * @param  list<array<mixed>>  $lines
     * @param  callable(array<mixed>): bool  $test
     */
    protected function everyLine(array $lines, callable $test): bool
    {
        foreach ($lines as $line) {
            if (! $test($line)) {
                return false;
            }
        }

        return $lines !== [];
    }

    /**
     * A spec by name, case-insensitively: an admin typing "вес" into the
     * settings and a supplier's spreadsheet writing "Вес" mean the same key.
     *
     * @param  array<string, string>  $features
     */
    protected function feature(array $features, string $key): ?string
    {
        if ($key === '') {
            return null;
        }

        foreach ($features as $name => $value) {
            if ($this->same($name, $key)) {
                return (string) $value;
            }
        }

        return null;
    }

    protected function same(?string $a, ?string $b): bool
    {
        return $a !== null && $b !== null && mb_strtolower(trim($a)) === mb_strtolower(trim($b));
    }
}
