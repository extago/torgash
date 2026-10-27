<?php

namespace Torgash\Checkout\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Coupon;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Orders\OrderDiscounts;
use Torgash\Checkout\Orders\OrderTotals;

/**
 * A discount on an order that has already been placed — "a regular customer
 * forgot to type the coupon", "a discount for the late delivery".
 *
 * Every act goes through OrderTotals, so the total, the taxes' snapshot, the
 * payment balance and the 1C document follow exactly as they do for any edit
 * of the order's contents, and every act writes a line into the order's
 * history saying who did what. Answers with the items card, like the editor
 * it lives in; a refusal is said INSIDE that card (HTMX does not swap a 4xx,
 * so the honest 422 would look like a button that did nothing).
 */
class OrderDiscountsController extends Controller
{
    public function __construct(protected OrderDiscounts $discounts, protected OrderTotals $totals) {}

    public function applyCoupon(Request $request, Order $order)
    {
        $code = mb_strtoupper(trim((string) $request->input('code')));
        $coupon = $code !== '' ? Coupon::where('code', $code)->first() : null;

        if (! $coupon || ! $coupon->enabled) {
            return $this->editor($order, __('No enabled coupon with that code.'));
        }

        DB::transaction(function () use ($order, $coupon) {
            $was = $order->coupon_code;
            $notes = $this->discounts->useCoupon($order, $coupon);
            $this->totals->recalculate($order);

            $key = ($was ? 'Coupon :from → :code' : 'Coupon :code').($notes ? ' (applied by hand although :why)' : '');
            OrderLog::record($order, $key, array_filter([
                'from' => $was,
                'code' => $coupon->code,
                'why' => $notes ? implode(', ', $notes) : null,
            ], fn ($v) => $v !== null));
        });

        return $this->editor($order->fresh());
    }

    public function removeCoupon(Order $order)
    {
        if ($order->coupon_code || $order->couponDiscount() > 0) {
            DB::transaction(function () use ($order) {
                $was = $order->coupon_code;
                $this->discounts->releaseCoupon($order);
                $this->totals->recalculate($order);
                $was
                    ? OrderLog::record($order, 'Coupon removed: :code', ['code' => $was])
                    : OrderLog::record($order, 'Coupon removed');
            });
        }

        return $this->editor($order->fresh());
    }

    public function setDiscount(Request $request, Order $order)
    {
        $type = $request->input('type') === 'percent' ? 'percent' : 'fixed';
        $raw = str_replace([' ', ','], ['', '.'], (string) $request->input('value'));
        $reason = trim((string) $request->input('reason'));

        if (! is_numeric($raw) || (float) $raw <= 0) {
            return $this->editor($order, __('Say how much: a percent or an amount above zero.'));
        }
        if ($reason === '') {
            // The one thing the next person looking at this order will ask.
            return $this->editor($order, __('Say why — the next person to open this order will ask.'));
        }

        // A percent is the number itself; an amount is typed in the books'
        // own currency, like the prices beside it, and stored in minor units.
        $value = $type === 'percent' ? (int) round((float) $raw) : (int) round((float) $raw * 100);

        DB::transaction(function () use ($order, $type, $value, $reason, $request) {
            $this->discounts->setManual($order, $type, $value, mb_substr($reason, 0, 255), $request->user());
            $this->totals->recalculate($order);

            OrderLog::record($order, 'Manager’s discount: :amount — :reason', [
                'amount' => $type === 'percent' ? $value.'%' : format_price($value, $order->currency_code),
                'reason' => $reason,
            ]);
        });

        return $this->editor($order->fresh());
    }

    public function removeDiscount(Order $order)
    {
        if ($order->manual_discount) {
            DB::transaction(function () use ($order) {
                $this->discounts->clearManual($order);
                $this->totals->recalculate($order);
                OrderLog::record($order, 'Manager’s discount removed');
            });
        }

        return $this->editor($order->fresh());
    }

    protected function editor(Order $order, ?string $error = null)
    {
        return view('torgash.checkout::admin.orders._editor', [
            'order' => $order,
            'editing' => true,
            'oob' => true,
            'discountError' => $error,
        ]);
    }
}
