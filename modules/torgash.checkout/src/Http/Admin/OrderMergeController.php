<?php

namespace Torgash\Checkout\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use RuntimeException;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderMerge;
use Torgash\Checkout\Orders\MergeOrders;

/**
 * "The customer ordered twice and wants one parcel" (PLAN §40.10/4), from the
 * order it all goes INTO. The other order is named by its number, shown with
 * what will happen before anything moves — or with why it cannot — and only
 * the second press merges. Everything is MergeOrders; this is the screen.
 */
class OrderMergeController extends Controller
{
    public function __construct(protected MergeOrders $merging) {}

    /** The window's body: the other order and what merging it would do. */
    public function preview(Request $request, Order $order)
    {
        $number = trim((string) $request->query('number', ''));
        $other = $number === '' ? null : Order::where('order_number', $number)
            ->orWhere('order_number', 'ilike', '%'.$number)
            ->orderByRaw('order_number = ? desc', [$number])
            ->first();

        return view('torgash.checkout::admin.orders._merge', [
            'order' => $order,
            'number' => $number,
            'other' => $other,
            'refusals' => $other ? $this->merging->refusals($order, $other) : [],
            // What the owner of this screen looks for next: this customer's
            // other orders that could go in.
            'candidates' => Order::query()
                ->where('id', '!=', $order->id)
                ->whereNull('merged_into_id')
                ->where(fn ($q) => $q
                    ->when($order->user_id, fn ($q) => $q->where('user_id', $order->user_id))
                    ->when($order->customer_ref, fn ($q) => $q->orWhere('customer_ref', $order->customer_ref)))
                ->when(! $order->user_id && ! $order->customer_ref, fn ($q) => $q->whereRaw('false'))
                ->latest('id')
                ->limit(8)
                ->get(),
        ]);
    }

    public function merge(Request $request, Order $order)
    {
        $from = Order::where('order_number', (string) $request->input('from'))->firstOrFail();

        try {
            $this->merging->merge($order, $from, $request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.orders.show', $order)->withErrors($e->getMessage());
        }

        return redirect()->route('admin.orders.show', $order)
            ->with('status', __('Order :number merged into this one.', ['number' => $from->order_number]));
    }

    public function undo(Request $request, Order $order, OrderMerge $merge)
    {
        abort_unless($merge->into_id === $order->id, 404);

        try {
            $this->merging->undo($merge, $request->user());
        } catch (RuntimeException $e) {
            return redirect()->route('admin.orders.show', $order)->withErrors($e->getMessage());
        }

        return redirect()->route('admin.orders.show', $order)
            ->with('status', __('The merge is undone: order :number is on its own again.', ['number' => $merge->from->order_number]));
    }
}
