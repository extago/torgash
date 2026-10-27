<?php

namespace Torgash\Inventory\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;
use Torgash\Inventory\Profit;

/**
 * An order's own expenses and income (PLAN §42.5а) — added and taken off on
 * the order's card, answered with the card again. The shop's books only:
 * nothing here touches what the customer pays.
 */
class OrderCostsController extends Controller
{
    public function store(Request $request, Order $order)
    {
        $validated = $request->validate([
            'kind' => ['required', 'in:expense,income'],
            'label' => ['required', 'string', 'max:190'],
            'amount' => ['required', 'string', 'max:32'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $amount = trim(str_replace([' ', ','], ['', '.'], $validated['amount']));

        if (! is_numeric($amount) || (float) $amount <= 0) {
            return $this->panel($order, __('Type an amount greater than zero.'));
        }

        DB::table('m_inventory_order_costs')->insert([
            'order_id' => $order->id,
            'kind' => $validated['kind'],
            'label' => trim($validated['label']),
            'amount' => (int) round((float) $amount * 100),
            'note' => $validated['note'] ?? null,
            'source' => 'manual',
            'user_id' => $request->user()?->id,
            'user_name' => $request->user()?->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->panel($order);
    }

    public function destroy(Order $order, int $cost)
    {
        DB::table('m_inventory_order_costs')->where('order_id', $order->id)->where('id', $cost)->delete();

        return $this->panel($order);
    }

    protected function panel(Order $order, ?string $error = null)
    {
        return view('torgash.inventory::partials.order-panel', [
            'order' => $order,
            'profit' => app(Profit::class)->forOrders([$order->id])[$order->id] ?? null,
            'rows' => DB::table('m_inventory_order_costs')->where('order_id', $order->id)->orderBy('id')->get(),
            'labels' => app(Profit::class)->labels(),
            'costError' => $error,
        ]);
    }
}
