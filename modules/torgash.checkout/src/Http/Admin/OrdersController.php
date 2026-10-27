<?php

namespace Torgash\Checkout\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\ClientFacts;
use Torgash\Checkout\Orders\MethodNames;
use Torgash\Checkout\Orders\OrderFilter;
use Torgash\Checkout\Orders\OrderStatusChanger;
use Torgash\Checkout\Orders\OrderTrash;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Checkout\Orders\RecordPayment;

class OrdersController extends Controller
{
    public function index(Request $request)
    {
        $filter = new OrderFilter($request);
        // The trash is a view of the same list: every filter still applies.
        $trashed = $request->boolean('trashed');

        $orders = $filter->apply($trashed ? Order::onlyTrashed() : Order::query())
            ->with('manager', 'paymentMethod')
            ->latest()
            ->paginate(20)
            ->withQueryString();

        // The status tabs count what the OTHER filters found, so "Paid" and a
        // search for a surname can be read together: one GROUP BY, the status
        // filter itself left out of it.
        $withoutStatus = clone $filter;
        $withoutStatus->values['status'] = [];
        $statusCounts = $withoutStatus->apply($trashed ? Order::onlyTrashed() : Order::query())
            ->selectRaw('status, count(*) as n')->groupBy('status')
            ->pluck('n', 'status')->map(fn ($n) => (int) $n);

        return view('torgash.checkout::admin.orders.index', [
            'orders' => $orders,
            'filter' => $filter,
            'statusCounts' => $statusCounts,
            'trashed' => $trashed,
            'trashedCount' => Order::onlyTrashed()->count(),
            'statuses' => OrderStatus::orderBy('position')->get()->keyBy('code'),
            'managers' => $this->managers(),
            // Offered from what orders actually carry rather than from the
            // method tables: an order keeps the NAME it was sold under, and a
            // method that has since been renamed or uninstalled must stay
            // findable. Two cheap DISTINCTs on an indexed-enough table.
            'paymentNames' => (new MethodNames('payment'))->options($this->distinct('payment_method_name')),
            'shippingNames' => (new MethodNames('shipping'))->options($this->distinct('shipping_method_name')),
        ]);
    }

    public function show(Order $order)
    {
        return view('torgash.checkout::admin.orders.show', [
            'order' => $order,
            // A merged order's own status is shown, never offered.
            'statuses' => $order->status === OrderStatus::MERGED ? OrderStatus::orderBy('position')->get() : OrderStatus::choosable()->get(),
            'managers' => $this->managers(),
            'paymentMethods' => PaymentMethod::where('enabled', true)->orderBy('position')->get(),
            'editing' => false,
            // "Five orders, five names, one address" is what fraud looks like,
            // so the card says how many others came from here.
            'sameAddressOrders' => $order->ip_address
                ? Order::where('ip_address', $order->ip_address)->whereKeyNot($order->getKey())->count()
                : 0,
            'clientFacts' => app(ClientFacts::class)->describe($order),
        ]);
    }

    /**
     * The invoice as the CUSTOMER reads it: in the order's own language by
     * default (the one it was placed in), or in any other language of the
     * storefront it was placed on (`?lang=`) — a manager printing it is not
     * the reader it is for. Rendered here, inside the switched locale, and the
     * admin's own locale put back before the response leaves.
     */
    public function invoice(Request $request, Order $order)
    {
        $storefront = \App\Models\Storefront::find($order->storefront_id);
        // The storefront's own languages; an order whose storefront is gone
        // (or never recorded) may be printed in any language the shop has on.
        $languages = array_values(array_unique(array_filter([
            ...(array) ($storefront?->locales ?: \App\Models\Language::where('enabled', true)->orderBy('sort')->pluck('code')->all()),
            $order->locale,
        ]))) ?: [content_base_locale()];

        $asked = (string) $request->query('lang', '');
        $lang = in_array($asked, $languages, true) ? $asked : ($order->locale && in_array($order->locale, $languages, true) ? $order->locale : $languages[0]);

        $adminLocale = app()->getLocale();
        app()->setLocale($lang);
        \Illuminate\Support\Carbon::setLocale($lang);

        try {
            $html = view('torgash.checkout::invoice', [
                'order' => $order,
                'backUrl' => route('admin.orders.show', $order),
                'storefront' => $storefront,
                'invoiceLanguages' => $languages,
                'invoiceLanguage' => $lang,
                // The switch is the admin's own control: labelled in the
                // admin's language, never printed.
                'adminLocale' => $adminLocale,
            ])->render();
        } finally {
            app()->setLocale($adminLocale);
            \Illuminate\Support\Carbon::setLocale($adminLocale);
        }

        return response($html);
    }

    /**
     * The stock arithmetic, the transaction and the announcement live in
     * OrderStatusChanger — an automation rule sets statuses too, and this is the
     * screen version of the same act, not a second implementation of it.
     */
    public function updateStatus(Request $request, Order $order, OrderStatusChanger $changer)
    {
        $validated = $request->validate([
            'status' => 'required|exists:m_checkout_order_statuses,code',
        ]);

        try {
            $changer->change($order, OrderStatus::findOrFail($validated['status']));
        } catch (\RuntimeException $e) {
            // A merged order's status is closed; the select is not drawn for
            // one, so this is a hand-made request — answered in words.
            return response($e->getMessage(), 422);
        }

        // From the list, the answer is the row's own status cell.
        if ($request->boolean('cell')) {
            return view('torgash.checkout::admin.orders._status_cell', [
                'order' => $order->fresh(),
                'statuses' => OrderStatus::orderBy('position')->get()->keyBy('code'),
            ]);
        }

        return view('torgash.checkout::admin.orders._status', [
            'order' => $order->fresh(),
            'statuses' => OrderStatus::choosable()->get(),
        ]);
    }

    /**
     * Several orders at once: a status, a manager, or "the money arrived".
     *
     * Each order goes through the very code its own page uses —
     * OrderStatusChanger (stock, the log, the customer's email), RecordPayment —
     * one by one, so a bulk change is exactly N single changes and nothing a
     * single change would refuse slips through. One that fails is counted and
     * named, never allowed to stop the rest.
     */
    public function bulk(Request $request, OrderStatusChanger $changer, RecordPayment $payments)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['status', 'manager', 'paid', 'trash', 'restore', 'purge'])],
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer',
            'status' => ['required_if:action,status', 'nullable', 'exists:m_checkout_order_statuses,code'],
            'manager_id' => ['nullable', 'integer'],
        ]);

        $managerId = $validated['action'] === 'manager' ? ($validated['manager_id'] ?? null) : null;
        if ($managerId !== null && ! User::query()->staff()->whereKey($managerId)->exists()) {
            return back()->withErrors(['manager_id' => __('That account is not a staff member.')]);
        }

        // Restoring and purging act on the trash, everything else on live orders.
        $orders = (in_array($validated['action'], ['restore', 'purge'], true) ? Order::onlyTrashed() : Order::query())
            ->whereIn('id', $validated['ids'])->with('manager')->get();
        $trash = app(OrderTrash::class);
        $status = $validated['action'] === 'status' ? OrderStatus::findOrFail($validated['status']) : null;
        $failed = [];
        $refused = [];

        foreach ($orders as $order) {
            try {
                match ($validated['action']) {
                    'status' => $order->status === $status->code ? null : $changer->change($order, $status),
                    'paid' => $payments->record($order, PaymentState::PAID),
                    'manager' => $this->assignManager($order, $managerId),
                    'trash' => ($why = $trash->refusal($order)) ? throw new \RuntimeException($why) : $trash->trash($order),
                    'restore' => $trash->restore($order),
                    'purge' => $trash->purge($order),
                };
            } catch (\RuntimeException $e) {
                // A refusal the shop is meant to read: money on an order being trashed.
                $refused[] = $order->order_number;
            } catch (\Throwable $e) {
                report($e);
                $failed[] = $order->order_number;
            }
        }

        $done = $orders->count() - count($failed) - count($refused);
        $response = back()->with('status', trans_choice(':count order updated.|:count orders updated.', $done, ['count' => $done]));

        $errors = array_filter([
            'refused' => $refused ? __('Money has been received for these orders, so they stay: :orders. Refund or correct the payment first.', ['orders' => implode(', ', $refused)]) : null,
            'bulk' => $failed ? __('Not changed: :orders.', ['orders' => implode(', ', $failed)]) : null,
        ]);

        return $errors ? $response->withErrors($errors) : $response;
    }

    /** One order to the trash — from its own page. */
    public function destroy(Order $order, OrderTrash $trash)
    {
        if ($why = $trash->refusal($order)) {
            return back()->withErrors(['trash' => $why]);
        }

        $trash->trash($order);

        return redirect()->route('admin.orders.index')->with('status', __('Order :number moved to the trash.', ['number' => $order->order_number]));
    }

    public function restore(string $number, OrderTrash $trash)
    {
        $order = Order::onlyTrashed()->where('order_number', $number)->firstOrFail();
        $trash->restore($order);

        return redirect()->route('admin.orders.show', $order)->with('status', __('Order restored.'));
    }

    public function purge(string $number, OrderTrash $trash)
    {
        $order = Order::onlyTrashed()->where('order_number', $number)->firstOrFail();
        $trash->purge($order);

        return redirect()->route('admin.orders.index', ['trashed' => 1])->with('status', __('Order deleted for good.'));
    }

    /** The manager change and its log line — one order's page and the bulk bar both. */
    protected function assignManager(Order $order, ?int $managerId): void
    {
        $was = $order->manager?->name;
        $order->update(['manager_id' => $managerId]);
        $order->load('manager');

        OrderLog::record($order, 'Manager: :from → :to', [
            'from' => $was ?? OrderLog::word('unassigned'),
            'to' => $order->manager?->name ?? OrderLog::word('unassigned'),
        ]);
    }

    /** Assign or clear the employee dealing with this order. */
    public function updateManager(Request $request, Order $order)
    {
        $validated = $request->validate([
            'manager_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $managerId = $validated['manager_id'] ?? null;

        // Only a staff account can hold an order: assigning a customer would
        // make the list's "manager" filter meaningless and hand the order to
        // somebody who cannot open it.
        if ($managerId !== null && ! User::query()->staff()->whereKey($managerId)->exists()) {
            return back()->withErrors(['manager_id' => __('That account is not a staff member.')]);
        }

        $this->assignManager($order, $managerId);

        return view('torgash.checkout::admin.orders._manager', [
            'order' => $order,
            'managers' => $this->managers(),
        ]);
    }

    /**
     * The order stores the payment method's NAME, not its id — a frozen
     * snapshot, so an order survives the method being renamed or deleted. This
     * writes a new snapshot from a method that exists today.
     */
    public function updatePayment(Request $request, Order $order)
    {
        $validated = $request->validate([
            'payment_method_id' => ['required', 'integer', 'exists:m_checkout_payment_methods,id'],
        ]);

        $method = PaymentMethod::findOrFail($validated['payment_method_id']);
        $was = $order->payment_method_name;

        // Both, together: the name the order now prints and the link the
        // gateway is found by. Writing one without the other is how an order
        // ends up quoting one method and being charged by another's.
        $order->update([
            'payment_method_name' => $method->t('name'),
            'payment_method_id' => $method->id,
        ]);

        OrderLog::record($order, 'Payment method: :from → :to', ['from' => $was ?? '—', 'to' => $order->payment_method_name]);

        return view('torgash.checkout::admin.orders._payment', [
            'order' => $order,
            'paymentMethods' => PaymentMethod::where('enabled', true)->orderBy('position')->get(),
        ]);
    }

    /**
     * Record where the money is, by hand.
     *
     * Today this is the only way it moves: no gateway exists yet, and a shop
     * taking cash on delivery or a bank transfer will keep using it after one
     * does — somebody looks at a statement and says "this one is settled". The
     * amount is asked for rather than assumed from the total, because an
     * underpayment and a partial refund are both real and both need a number.
     */
    public function updatePaymentState(Request $request, Order $order, RecordPayment $payments)
    {
        $validated = $request->validate([
            'payment_state' => ['required', 'string', Rule::in(PaymentState::all())],
            'paid_total' => ['nullable', 'string'],
        ]);

        // Everything else about settling an order — zeroing an amount that no
        // longer means money, stamping paid_at once, the log line — is in
        // RecordPayment, because 1C settles orders too and a second copy of
        // those rules is how the two screens start disagreeing.
        $payments->record($order, $validated['payment_state'], $this->minorUnits($validated['paid_total'] ?? ''));

        return view('torgash.checkout::admin.orders._payment_state', ['order' => $order->fresh()]);
    }

    /**
     * An amount typed by a human, in the currency's own units, as minor units.
     * Blank is null — "all of it" — which is the common case: they paid it.
     */
    protected function minorUnits(string $value): ?int
    {
        $value = trim(str_replace([' ', ','], ['', '.'], $value));

        return $value === '' ? null : max(0, (int) round((float) $value * 100));
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    protected function managers()
    {
        return User::query()->staff()->orderBy('name')->get(['id', 'name']);
    }

    /** @return list<string> the distinct non-null values of a snapshot column */
    protected function distinct(string $column): array
    {
        return Order::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();
    }
}
