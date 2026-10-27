<?php

namespace Torgash\Shipping\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Orders\OrderTotals;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * How a placed order ships, after the fact.
 *
 * Two jobs, both of them a human overruling the automatic answer:
 * "someone tell me what this delivery costs" (an order placed with a
 * quote-on-request option is a real order that simply has no delivery price
 * yet), and "this is going out a different way than the customer picked".
 *
 * The screen both appear on belongs to `torgash.checkout`; these endpoints are
 * delivery's, hung off its `checkout.order.shipping_extra` hook, so
 * uninstalling delivery takes them with it and leaves the order card intact.
 * The total is recomputed by checkout's own OrderTotals — the formula lives in
 * one place, and this module does not get its own opinion about it.
 */
class OrderShippingController extends Controller
{
    public function __construct(protected OrderTotals $totals) {}

    public function setCost(Request $request, Order $order)
    {
        $validated = $request->validate([
            'shipping_cost' => 'required|numeric|min:0',
        ]);

        $cost = $this->minorUnits($validated['shipping_cost']);
        $details = (array) ($order->shipping_details ?? []);

        DB::transaction(function () use ($order, $cost, $details) {
            $was = $order->shipping_cost;

            $order->shipping_cost = $cost;
            // The reason is kept, the flag is not: the order remembers why it
            // was placed without a price, and stops asking for one.
            $order->shipping_details = ['pending_quote' => false] + $details;
            $order->save();

            $this->totals->recalculate($order);

            OrderLog::record($order, 'Delivery priced: :from → :to', [
                'from' => format_price($was, $order->currency_code),
                'to' => format_price($cost, $order->currency_code),
            ]);
        });

        return back()->with('status', __('Delivery cost set. The order total has been recalculated.'));
    }

    /**
     * Change the method the order ships by, and what it costs.
     *
     * The price is typed, not re-quoted: re-quoting would mean rebuilding the
     * customer's location and a package from the order and asking the carrier
     * again, which is a different (and much larger) feature — see PLAN §17.
     * So when the METHOD changes, the carrier's frozen snapshot — the branch
     * the customer picked, the promised date — stops describing reality. It is
     * not thrown away (it is what the customer was told) but moved under
     * `previous` and the delivery is marked as set by hand.
     */
    public function updateMethod(Request $request, Order $order)
    {
        $validated = $request->validate([
            'shipping_method_name' => ['nullable', 'string', 'max:255'],
            'shipping_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $name = trim((string) ($validated['shipping_method_name'] ?? '')) ?: null;

        // Free text is not accepted: the name has to be one a method really
        // carries, or the one this order already had (its method may have been
        // renamed or uninstalled since, and re-saving must not silently
        // rewrite what the customer was sold).
        if ($name !== null && ! in_array($name, $this->offeredNames($order), true)) {
            return back()->withErrors(['shipping_method_name' => __('Unknown delivery method.')]);
        }

        $cost = $this->minorUnits($validated['shipping_cost']);
        $wasName = $order->shipping_method_name;
        $wasCost = $order->shipping_cost;

        if ($wasName === $name && $wasCost === $cost) {
            return back();
        }

        DB::transaction(function () use ($order, $name, $cost, $wasName, $wasCost) {
            $details = (array) ($order->shipping_details ?? []);

            if ($wasName !== $name) {
                $details = ['manual' => true, 'pending_quote' => false]
                    + ($details === [] ? [] : ['previous' => $details]);
            } else {
                $details = ['pending_quote' => false] + $details;
            }

            $order->shipping_method_name = $name;
            $order->shipping_cost = $cost;
            $order->shipping_details = $details;
            $order->save();

            $this->totals->recalculate($order);

            OrderLog::record($order, 'Delivery: :from (:from_cost) → :to (:to_cost)', [
                'from' => $wasName ?? '—',
                'from_cost' => format_price($wasCost, $order->currency_code),
                'to' => $name ?? '—',
                'to_cost' => format_price($cost, $order->currency_code),
            ]);
        });

        return back()->with('status', __('Delivery updated. The order total has been recalculated.'));
    }

    /**
     * What the select may be set to: every enabled method, plus whatever this
     * order already says, so an order shipped by a method that has since been
     * deleted can still be saved without losing its own history.
     *
     * @return list<string>
     */
    public function offeredNames(Order $order): array
    {
        $names = ShippingMethod::where('enabled', true)
            ->orderBy('position')
            ->get()
            // The order's own method keeps the words it was sold under as the
            // value — choosing it again is not a change of method — and the
            // select shows it in the reader's language (method.blade.php).
            ->map(fn (ShippingMethod $method) => in_array($order->shipping_method_name, (array) $method->name, true)
                ? $order->shipping_method_name
                : $method->t('name'))
            ->all();

        if ($order->shipping_method_name && ! in_array($order->shipping_method_name, $names, true)) {
            array_unshift($names, $order->shipping_method_name);
        }

        return array_values(array_unique($names));
    }

    protected function minorUnits(mixed $value): int
    {
        return (int) round((float) str_replace(',', '.', (string) $value) * 100);
    }
}
