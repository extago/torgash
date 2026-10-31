<?php

namespace Torgash\Checkout\Orders;

use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Torgash\Checkout\Models\CheckoutField;
use Torgash\Checkout\Models\Order;

/**
 * The shop's own questions at checkout (Models\CheckoutField): what the form
 * draws, what a placement must answer, and what the order keeps — a list of
 * {code, label, value} in the shop's base language, in the order asked.
 *
 * Kept as a LIST on the order (`m_checkout_orders.fields`), not in the
 * notes: the notes are the customer's own words and a manager edits them,
 * while an answer is read by code — a letter, an automation
 * (`order.fields.<code>`), an export, the API.
 */
class CheckoutFields
{
    /** @return Collection<int, CheckoutField> */
    public function asked(?string $place = null): Collection
    {
        return CheckoutField::query()->asked()
            ->when($place, fn ($query) => $query->where('place', $place))
            ->get();
    }

    /**
     * What a placement must supply: `fields.<code>`, required only where the
     * field is asked of THIS order (its buyer, its delivery).
     *
     * @return array<string, mixed>
     */
    public function rules(?string $buyer, ?string $offerId, bool $needsDelivery = true): array
    {
        $rules = ['fields' => 'nullable|array'];

        foreach ($this->asked() as $field) {
            $applies = $field->appliesTo($buyer, $offerId, $needsDelivery);
            $rule = [$applies && $field->required ? 'required' : 'nullable'];

            $rule = [...$rule, ...match ($field->type) {
                'textarea' => ['string', 'max:2000'],
                'select', 'radio' => [Rule::in(array_map('strval', array_keys($field->choices())))],
                'checkbox' => $applies && $field->required ? ['accepted'] : ['boolean'],
                'date' => ['date'],
                default => ['string', 'max:255'],
            }];

            $rules['fields.'.$field->code] = $rule;
        }

        return $rules;
    }

    /**
     * The order's answers, from the validated `fields` — only the fields
     * asked of it, blanks dropped; a choice and a tick written in the
     * shop's base language.
     *
     * @param  array<string, mixed>  $typed
     * @return list<array{code: string, label: string, value: string}>|null
     */
    public function answers(array $typed, ?string $buyer, ?string $offerId, bool $needsDelivery = true): ?array
    {
        $base = content_base_locale();
        $answers = [];

        foreach ($this->asked() as $field) {
            if (! $field->appliesTo($buyer, $offerId, $needsDelivery)) {
                continue;
            }

            $raw = $typed[$field->code] ?? null;
            $value = match ($field->type) {
                'select', 'radio' => $raw === null || $raw === '' ? '' : (string) ($field->choices($base)[(int) $raw] ?? ''),
                'checkbox' => filter_var($raw, FILTER_VALIDATE_BOOL) ? __('Yes', [], $base) : '',
                default => trim((string) $raw),
            };

            if ($value !== '') {
                $answers[] = ['code' => (string) $field->code, 'label' => (string) $field->t('label', $base), 'value' => $value];
            }
        }

        return $answers ?: null;
    }

    /** @return array<string, string> an order's answers by code */
    public static function byCode(Order $order): array
    {
        return collect($order->fields ?? [])->mapWithKeys(fn (array $answer) => [(string) $answer['code'] => (string) $answer['value']])->all();
    }

    /** An order's answers as lines of "Label: value" — for a letter, an automation's text, an export cell. */
    public static function text(Order $order, string $glue = "\n"): string
    {
        return collect($order->fields ?? [])->map(fn (array $answer) => $answer['label'].': '.$answer['value'])->implode($glue);
    }
}
