<?php

namespace Torgash\Options;

use Torgash\Catalog\Models\Product;
use Torgash\Options\Models\OptionChoice;

/**
 * What an answer COSTS — an open registry, answered lazily through
 * `options.price_rules`, the same shape as the catalogue's sorts and filter
 * flags (PLAN §33.4).
 *
 * Every rule answers one question: how much does this choice ADD to one unit
 * of the product it is asked about. Always an addition, never a new price, and
 * always measured against the product's own price BEFORE any option — which is
 * the whole reason a configurator here cannot surprise anybody: the order the
 * groups happen to be in does not change the money, and there is no such thing
 * as a multiplier applied to another multiplier.
 *
 * Five rules cover what the owner described (§33): a fixed amount, a percentage
 * of the product, a warranty that is "500 plus 10% of the product", a plain
 * multiplier, and "whatever the linked product costs, less 10%". A module can
 * add its own — per metre, per kilogram of the product — without touching this
 * class.
 *
 * The typed amount is money and therefore follows the engine's money rule: the
 * books column is derived, `amount_original` + `currency` remember what was
 * typed. A percentage is not money and needs none of that. The owner's own
 * words on the subject (§33.8): a service is a formula with a currency, not a
 * linked product.
 */
class PriceRules
{
    /** @var array<string, array<string, mixed>>|null */
    protected ?array $rules = null;

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        if ($this->rules !== null) {
            return $this->rules;
        }

        $rules = [];

        foreach (hook_filter('options.price_rules', $this->built(), []) as $key => $definition) {
            if (! is_string($key) || $key === '' || ! is_callable($definition['apply'] ?? null)) {
                continue;
            }

            $rules[$key] = $definition + ['label' => [], 'fields' => [], 'describe' => null];
        }

        return $this->rules = $rules;
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function label(string $key): string
    {
        $label = $this->find($key)['label'] ?? [];

        return label_text($label, null, $key);
    }

    /** key => label, for the admin's select. */
    public function options(): array
    {
        $options = [];

        foreach (array_keys($this->all()) as $key) {
            $options[$key] = $this->label($key);
        }

        return $options;
    }

    /** Which inputs this rule actually uses — the form shows only those. */
    public function fields(string $key): array
    {
        return (array) ($this->find($key)['fields'] ?? []);
    }

    /**
     * What this choice adds to one unit of a product whose own price is
     * `$basePrice`. A rule nobody answers for adds NOTHING — fail closed, the
     * rule every registry in this engine follows: a module gone from the shop
     * must not leave a price it can no longer explain.
     */
    public function apply(OptionChoice $choice, int $basePrice, ?Product $chosen = null): int
    {
        $rule = $this->find((string) $choice->price_rule);

        return $rule ? (int) ($rule['apply'])($basePrice, $choice, $chosen) : 0;
    }

    /** The rule in words, for the admin's own lists. */
    public function describe(OptionChoice $choice): string
    {
        $rule = $this->find((string) $choice->price_rule);

        if (! $rule) {
            return '—';
        }

        return is_callable($rule['describe'] ?? null)
            ? (string) ($rule['describe'])($choice)
            : $this->label((string) $choice->price_rule);
    }

    /** For tests and for a screen that has just saved. */
    public function flush(): void
    {
        $this->rules = null;
    }

    /**
     * The rules this module ships. Seeded through the same filter a module
     * extends, so nothing here is privileged.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function built(): array
    {
        return [
            'fixed' => [
                'label' => 'A fixed amount',
                'fields' => ['amount'],
                'apply' => fn (int $base, OptionChoice $choice) => (int) $choice->amount,
                'describe' => fn (OptionChoice $choice) => static::money($choice),
            ],

            'percent' => [
                'label' => 'A percentage of the product price',
                'fields' => ['percent'],
                'apply' => fn (int $base, OptionChoice $choice) => round_money($base * (float) $choice->percent / 100),
                'describe' => fn (OptionChoice $choice) => static::signed((float) $choice->percent).'%',
            ],

            // The owner's own example: an extended warranty is a fee plus a
            // share of what is being insured.
            'fixed_plus_percent' => [
                'label' => 'An amount plus a percentage of the product',
                'fields' => ['amount', 'percent'],
                'apply' => fn (int $base, OptionChoice $choice) => (int) $choice->amount
                    + round_money($base * (float) $choice->percent / 100),
                'describe' => fn (OptionChoice $choice) => static::money($choice).' + '.static::signed((float) $choice->percent).'%',
            ],

            // A factor, not a percentage: ×1.15 is what a shop says out loud,
            // and the ADDITION is what this engine charges — so the two cannot
            // drift apart when a second multiplier is added to the same page.
            'multiplier' => [
                'label' => 'Multiply the product price',
                'fields' => ['percent'],
                'apply' => fn (int $base, OptionChoice $choice) => round_money($base * ((float) $choice->percent - 1)),
                'describe' => fn (OptionChoice $choice) => '×'.rtrim(rtrim(number_format((float) $choice->percent, 3, '.', ''), '0'), '.'),
            ],

            // What the linked product costs, optionally discounted — the only
            // rule that reads a price other than the product's own.
            'product_price' => [
                'label' => 'The linked product’s own price',
                'fields' => ['percent', 'amount'],
                'apply' => function (int $base, OptionChoice $choice, ?Product $chosen = null) {
                    $chosen ??= $choice->product;

                    if (! $chosen) {
                        return 0;
                    }

                    // The visitor's own price: a cart is always "my cart", and
                    // the child line this becomes is charged at exactly this.
                    $price = (int) $chosen->effectivePrice();
                    $percent = (float) ($choice->percent ?? 0);

                    return round_money($price * (100 + $percent) / 100) + (int) $choice->amount;
                },
                'describe' => function (OptionChoice $choice) {
                    $parts = [__('the product’s price')];

                    if ((float) $choice->percent !== 0.0) {
                        $parts[] = static::signed((float) $choice->percent).'%';
                    }

                    if ((int) $choice->amount !== 0) {
                        $parts[] = static::money($choice);
                    }

                    return implode(' ', $parts);
                },
            ],

            'free' => [
                'label' => 'Free',
                'fields' => [],
                'apply' => fn () => 0,
                'describe' => fn () => __('Free'),
            ],
        ];
    }

    /** The typed amount in the currency it was typed in, for an admin screen. */
    protected static function money(OptionChoice $choice): string
    {
        return $choice->currency && $choice->amount_original !== null
            ? format_price((int) $choice->amount_original, (string) $choice->currency)
            : admin_price((int) $choice->amount);
    }

    protected static function signed(float $number): string
    {
        return ($number > 0 ? '+' : '').rtrim(rtrim(number_format($number, 2, '.', ''), '0'), '.');
    }
}
