<?php

namespace Torgash\Giftcards;

use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Models\Product;
use Torgash\Giftcards\Models\GiftProduct;

/**
 * What a customer answers when buying a gift card, read from the cart line's
 * configuration bag under this module's own key — the way options and kits
 * keep theirs:
 *
 *     config[giftcard] = {amount, to_name, to_email, from_name, message, deliver_on}
 *
 * All of it optional but the amount on a card whose amount is typed; with no
 * recipient the card is the buyer's own. Normalised here (the cart computes the
 * line's key from what comes back, so two cards to two people are two lines and
 * the same card twice is one line of two) and refused here, by throwing, which
 * the cart turns back into the form.
 */
class GiftConfig
{
    public const KEY = 'giftcard';

    /**
     * Every gift card product, read ONCE per request: a shop has a handful of
     * them, so one query for all of them costs less than one per cart line or
     * per card on a page (the cart asks about every line — QueryBudgetTest).
     *
     * @var array<int, GiftProduct>|null
     */
    protected ?array $rows = null;

    public function row(Product|int|null $product): ?GiftProduct
    {
        $id = $product instanceof Product ? (int) $product->id : (int) $product;

        if ($id <= 0) {
            return null;
        }

        $this->rows ??= GiftProduct::all()->keyBy('product_id')->all();

        return $this->rows[$id] ?? null;
    }

    /** Kept for the page's announcement; the whole table is one read anyway. */
    public function prime(iterable $products): void
    {
        $this->rows ??= GiftProduct::all()->keyBy('product_id')->all();
    }

    public function isGift(Product|int|null $product): bool
    {
        return $this->row($product) !== null;
    }

    /**
     * @param  array<string, mixed>  $config  the whole bag
     * @return array<string, mixed> the whole bag, this module's part cleaned
     */
    public function normalise(Product $product, array $config): array
    {
        $row = $this->row($product);

        if (! $row) {
            unset($config[self::KEY]);

            return $config;
        }

        $typed = (array) ($config[self::KEY] ?? []);
        $clean = [];
        $errors = [];

        if ($row->free_amount) {
            $raw = str_replace([' ', ','], ['', '.'], (string) ($typed['amount'] ?? ''));
            $amount = is_numeric($raw) ? (int) round((float) $raw * 100) : 0;
            $min = $row->minimum();
            $max = $row->maximum();

            if ($amount <= 0) {
                $errors['config.giftcard.amount'] = __('Type the amount of the card.');
            } elseif ($amount < $min || ($max !== null && $amount > $max)) {
                $errors['config.giftcard.amount'] = $max !== null
                    ? __('The amount must be between :min and :max.', ['min' => format_price($min), 'max' => format_price($max)])
                    : __('The amount must be at least :min.', ['min' => format_price($min)]);
            } else {
                $clean['amount'] = $amount;
            }
        }

        $email = trim((string) ($typed['to_email'] ?? ''));

        if ($email !== '') {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors['config.giftcard.to_email'] = __('The recipient\'s email does not look right.');
            } else {
                $clean['to_email'] = mb_strtolower($email);
            }
        }

        foreach (['to_name' => 190, 'from_name' => 190, 'message' => 500] as $field => $limit) {
            $value = trim((string) ($typed[$field] ?? ''));

            if ($value !== '') {
                $clean[$field] = mb_substr($value, 0, $limit);
            }
        }

        $on = trim((string) ($typed['deliver_on'] ?? ''));

        if ($on !== '') {
            try {
                $date = Carbon::parse($on)->startOfDay();
            } catch (\Throwable) {
                $date = null;
            }

            if (! $date || $date->lt(today()) || $date->gt(today()->addYear())) {
                $errors['config.giftcard.deliver_on'] = __('Choose a delivery date from today to a year ahead.');
            } elseif ($date->gt(today())) {
                // Today is "now": no need to remember a date for it.
                $clean['deliver_on'] = $date->toDateString();
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($clean === []) {
            unset($config[self::KEY]);
        } else {
            $config[self::KEY] = $clean;
        }

        return $config;
    }

    /**
     * What the answers add to the line: the typed amount (as the difference
     * from the product's own price, so a card priced 0 costs exactly what was
     * typed) and the words the order keeps about whom it is for.
     *
     * @return list<array<string, mixed>>
     */
    public function parts(Product $product, array $config, ?int $base = null): array
    {
        if (! $this->isGift($product)) {
            return [];
        }

        $answers = (array) ($config[self::KEY] ?? []);
        $parts = [];

        if (isset($answers['amount'])) {
            $parts[] = [
                'code' => 'giftcard:amount',
                'label' => __('Card amount').': '.format_price((int) $answers['amount']),
                'group' => __('Card amount'),
                'per_unit' => true,
                'amount' => (int) $answers['amount'] - ($base ?? (int) $product->effectivePrice()),
            ];
        }

        if (! empty($answers['to_email']) || ! empty($answers['to_name'])) {
            $to = trim(($answers['to_name'] ?? '').(! empty($answers['to_email']) ? ' <'.$answers['to_email'].'>' : ''));
            $parts[] = ['code' => 'giftcard:to', 'label' => __('For').': '.$to, 'group' => __('For'), 'per_unit' => true, 'amount' => 0];
        }

        if (! empty($answers['from_name'])) {
            $parts[] = ['code' => 'giftcard:from', 'label' => __('From').': '.$answers['from_name'], 'group' => __('From'), 'per_unit' => true, 'amount' => 0];
        }

        if (! empty($answers['deliver_on'])) {
            $parts[] = [
                'code' => 'giftcard:on',
                'label' => __('Send on').': '.Carbon::parse($answers['deliver_on'])->format('d.m.Y'),
                'group' => __('Send on'),
                'per_unit' => true,
                'amount' => 0,
            ];
        }

        if (! empty($answers['message'])) {
            $parts[] = [
                'code' => 'giftcard:message',
                'label' => __('Message').': '.\Illuminate\Support\Str::limit($answers['message'], 60),
                'group' => __('Message'),
                'per_unit' => true,
                'amount' => 0,
            ];
        }

        return $parts;
    }
}
