<?php

namespace Torgash\Checkout\Orders;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The order list's search panel, translated into one query.
 *
 * Field set follows the usual orders search: free text, status,
 * period, total range, payment and shipping method, manager — including
 * "not assigned to anyone", which is the filter a shop actually opens the
 * screen for — and "contains this product".
 *
 * Every clause is skipped when its input is blank, so the same code serves the
 * unfiltered list. Nothing here trusts a value into raw SQL: the free-text
 * needle is bound, and the product filter is a bound jsonb containment test.
 */
class OrderFilter
{
    /** @var array<string,mixed> */
    public array $values;

    public function __construct(Request $request)
    {
        $this->values = [
            'q' => trim((string) $request->get('q', '')),
            'status' => array_values(array_filter((array) $request->get('status', []))),
            'payment_state' => array_values(array_filter((array) $request->get('payment_state', []))),
            'from' => trim((string) $request->get('from', '')),
            'to' => trim((string) $request->get('to', '')),
            'total_from' => trim((string) $request->get('total_from', '')),
            'total_to' => trim((string) $request->get('total_to', '')),
            'payment' => trim((string) $request->get('payment', '')),
            'shipping' => trim((string) $request->get('shipping', '')),
            'manager' => trim((string) $request->get('manager', '')),
            'product_id' => trim((string) $request->get('product_id', '')),
            // The code as the order froze it — the coupons list links here.
            'coupon' => strtoupper(trim((string) $request->get('coupon', ''))),
            // An account — the customer card links here with its own id.
            'customer' => trim((string) $request->get('customer', '')),
        ];
    }

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    /** True when anything at all is filtering, so the screen can say so. */
    public function active(): bool
    {
        return collect($this->values)->contains(fn ($value) => $value !== '' && $value !== []);
    }

    public function apply(Builder $query): Builder
    {
        $v = $this->values;

        if ($v['q'] !== '') {
            $needle = '%'.$v['q'].'%';
            $query->where(fn ($q) => $q
                ->where('order_number', 'ilike', $needle)
                ->orWhere('customer_name', 'ilike', $needle)
                ->orWhere('email', 'ilike', $needle)
                ->orWhere('phone', 'ilike', $needle)
                // The address an order came from: "what else came from here".
                ->orWhere('ip_address', 'ilike', $needle));
        }

        if ($v['status'] !== []) {
            $query->whereIn('status', $v['status']);
        }

        // Fulfilment and money are separate axes, so they are separate clauses:
        // "unpaid" is a question about the payment, not about the workflow, and
        // the two are meant to be asked together ("shipped but not paid").
        if ($v['payment_state'] !== []) {
            $query->whereIn('payment_state', $v['payment_state']);
        }

        // Dates come from <input type="date">, so they are days, not instants:
        // "to" has to cover the whole day or an order placed at 14:00 falls
        // outside a range that names its own date.
        if ($v['from'] !== '') {
            $query->whereDate('created_at', '>=', $v['from']);
        }
        if ($v['to'] !== '') {
            $query->whereDate('created_at', '<=', $v['to']);
        }

        if ($v['total_from'] !== '') {
            $query->where('total', '>=', $this->minorUnits($v['total_from']));
        }
        if ($v['total_to'] !== '') {
            $query->where('total', '<=', $this->minorUnits($v['total_to']));
        }

        // Methods are stored as the frozen NAME, not an id: the method row may
        // have been renamed or deleted since, and the order still says what it
        // was sold as. So this matches on that snapshot — in every language
        // the method is named in (MethodNames).
        if ($v['payment'] !== '') {
            $query->whereIn('payment_method_name', (new MethodNames('payment'))->spellings($v['payment']));
        }
        if ($v['shipping'] !== '') {
            $query->whereIn('shipping_method_name', (new MethodNames('shipping'))->spellings($v['shipping']));
        }

        if ($v['manager'] === 'none') {
            $query->whereNull('manager_id');
        } elseif ($v['manager'] !== '') {
            $query->where('manager_id', (int) $v['manager']);
        }

        if ($v['customer'] !== '') {
            $query->where('user_id', (int) $v['customer']);
        }

        if ($v['coupon'] !== '') {
            $query->whereRaw('upper(coupon_code) = ?', [$v['coupon']]);
        }

        if ($v['product_id'] !== '') {
            $query->whereRaw('items @> ?', [json_encode([['product_id' => (int) $v['product_id']]])]);
        }

        return $query;
    }

    protected function minorUnits(string $value): int
    {
        return (int) round((float) str_replace([' ', ','], ['', '.'], $value) * 100);
    }
}
