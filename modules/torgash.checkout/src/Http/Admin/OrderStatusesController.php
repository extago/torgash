<?php

namespace Torgash\Checkout\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;

class OrderStatusesController extends Controller
{
    public function index()
    {
        return view('torgash.checkout::admin.order_statuses.index', [
            'statuses' => OrderStatus::orderBy('position')->get(),
            // How many live orders stand in each — the list links them, and a
            // status in use is the one that cannot be deleted.
            'orderCounts' => Order::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->map(fn ($n) => (int) $n),
        ]);
    }

    public function create()
    {
        return view('torgash.checkout::admin.order_statuses.form', ['status' => new OrderStatus]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request, isNew: true);

        DB::transaction(function () use ($validated) {
            if ($validated['is_default']) {
                OrderStatus::where('is_default', true)->update(['is_default' => false]);
            }

            OrderStatus::create($validated);
        });

        return $this->afterSave($request, OrderStatus::findOrFail($validated['code']), __('Status created.'));
    }

    public function edit(OrderStatus $status)
    {
        return view('torgash.checkout::admin.order_statuses.form', ['status' => $status]);
    }

    public function update(Request $request, OrderStatus $status)
    {
        $validated = $this->validated($request, isNew: false, current: $status);

        DB::transaction(function () use ($validated, $status) {
            if ($validated['is_default'] && ! $status->is_default) {
                OrderStatus::where('is_default', true)->update(['is_default' => false]);
            }

            $status->update($validated);
        });

        return $this->afterSave($request, $status, __('Status saved.'));
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, OrderStatus $status, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.orders.statuses.index')
            : redirect()->route('admin.orders.statuses.edit', $status))->with('status', $message);
    }

    public function destroy(OrderStatus $status)
    {
        if (Order::where('status', $status->code)->exists()) {
            return back()->withErrors(__('This status is used by existing orders and cannot be deleted.'));
        }

        if ($status->is_default) {
            return back()->withErrors(__('Cannot delete the default status. Make another status default first.'));
        }

        $status->delete();

        return redirect()->route('admin.orders.statuses.index')->with('status', __('Status deleted.'));
    }

    protected function validated(Request $request, bool $isNew, ?OrderStatus $current = null): array
    {
        $codeRules = $isNew ? 'required|string|max:32|alpha_dash|unique:m_checkout_order_statuses,code' : 'prohibited';

        $validated = $request->validate([
            'code' => $codeRules,
            // In the admin's content language — see put_translation().
            'name' => [! $isNew && ! content_locale_is_base() ? 'nullable' : 'required', 'string'],
            'color' => 'required|string|max:7',
            'position' => 'nullable|integer',
            'inventory_action' => 'required|in:none,decrease,increase',
        ]);

        return [
            ...($isNew ? ['code' => $validated['code']] : []),
            'name' => put_translation($current?->name, $validated['name'] ?? null),
            'color' => $validated['color'],
            'position' => (int) ($validated['position'] ?? 0),
            'inventory_action' => $validated['inventory_action'],
            'is_default' => $request->boolean('is_default'),
        ];
    }
}
