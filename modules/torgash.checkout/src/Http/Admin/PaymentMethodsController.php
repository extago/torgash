<?php

namespace Torgash\Checkout\Http\Admin;

use App\Core\Support\Currencies;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OneClick;
use Torgash\Media\ImageProcessor;

class PaymentMethodsController extends Controller
{
    public function __construct(protected ImageProcessor $images) {}

    public function index(Request $request, OneClick $oneClick)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ['enabled', 'disabled'], true) ? $request->query('status') : '',
        ];

        // A shop has a handful of methods, so the search runs over what the
        // admin can SEE — the name in every language — rather than a jsonb
        // query per locale.
        $all = PaymentMethod::with('media')->orderBy('position')->orderBy('id')->get();
        $found = $filters['q'] === '' ? $all : $all->filter(fn (PaymentMethod $method) => collect((array) $method->name)
            ->contains(fn ($name) => mb_stripos((string) $name, $filters['q']) !== false));

        $statusCounts = [
            '' => $found->count(),
            'enabled' => $found->where('enabled', true)->count(),
            'disabled' => $found->where('enabled', false)->count(),
        ];

        $methods = match ($filters['status']) {
            'enabled' => $found->where('enabled', true),
            'disabled' => $found->where('enabled', false),
            default => $found,
        };

        return view('torgash.checkout::admin.payment_methods.index', [
            'methods' => $methods->values(),
            'filters' => $filters,
            'statusCounts' => $statusCounts,
            'orderCounts' => $this->orderCounts(),
            'oneClickId' => $oneClick->paymentMethod()?->id,
            // What else a module has to say about each row (a gateway, its test
            // mode) — asked ONCE for the whole list, answered as id => html, so
            // the module reads its own table in one query rather than per row.
            'notes' => (array) hook_filter('checkout.payment_methods.notes', [], ['methods' => $methods]),
        ]);
    }

    /** @return array<int, int> orders per method id — one GROUP BY, trash included. */
    protected function orderCounts(): array
    {
        return Order::withTrashed()->whereNotNull('payment_method_id')
            ->selectRaw('payment_method_id, count(*) as n')->groupBy('payment_method_id')
            ->pluck('n', 'payment_method_id')->map(fn ($n) => (int) $n)->all();
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, PaymentMethod $paymentMethod)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $paymentMethod->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.checkout::admin.payment_methods._status', ['method' => $paymentMethod]);
    }

    /**
     * Several at once, through the models so the audit hears each one. A method
     * orders were sold under is not deleted — it is what links those orders to
     * their gateway (a refund goes through it) — and the answer says which.
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $methods = PaymentMethod::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] !== 'delete') {
            $methods->each(fn (PaymentMethod $method) => $method->update(['enabled' => $validated['action'] === 'enable']));

            return back()->with('status', trans_choice(':count payment method updated.|:count payment methods updated.', $methods->count(), ['count' => $methods->count()]));
        }

        $used = $this->orderCounts();
        [$kept, $gone] = $methods->partition(fn (PaymentMethod $method) => ($used[$method->id] ?? 0) > 0);
        $gone->each->delete();

        $response = back()->with('status', trans_choice(':count payment method deleted.|:count payment methods deleted.', $gone->count(), ['count' => $gone->count()]));

        return $kept->isEmpty() ? $response : $response->withErrors(__('Orders were paid by these methods, so they stay — switch them off instead: :names.', [
            'names' => $kept->map(fn (PaymentMethod $method) => $method->t('name'))->implode(', '),
        ]));
    }

    public function create()
    {
        return view('torgash.checkout::admin.payment_methods.form', [
            'method' => new PaymentMethod,
            'currencies' => $this->currencies(),
        ]);
    }

    public function store(Request $request)
    {
        $method = PaymentMethod::create($this->data($request, new PaymentMethod));

        $this->syncLogo($request, $method);
        $this->saved($method, $request);

        return $this->afterSave($request, $method, __('Payment method created.'));
    }

    public function edit(PaymentMethod $paymentMethod)
    {
        return view('torgash.checkout::admin.payment_methods.form', [
            'method' => $paymentMethod,
            'currencies' => $this->currencies(),
        ]);
    }

    /**
     * The shop's currencies, for the "what this method accepts" card.
     *
     * Read through the request-scoped memo rather than queried here: the same
     * four rows are wanted by anything on the page that prints money.
     *
     * @return array<string, \App\Models\Currency>
     */
    protected function currencies(): array
    {
        return app(Currencies::class)->all();
    }

    public function update(Request $request, PaymentMethod $paymentMethod)
    {
        $paymentMethod->update($this->data($request, $paymentMethod));

        $this->syncLogo($request, $paymentMethod);
        $this->saved($paymentMethod, $request);

        return $this->afterSave($request, $paymentMethod, __('Payment method saved.'));
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, PaymentMethod $method, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.checkout.payment-methods.index')
            : redirect()->route('admin.checkout.payment-methods.edit', $method))->with('status', $message);
    }

    /**
     * The one funnel both store() and update() pass through, same idiom as
     * catalog.product.saved: a module that added fields to this form (a payment
     * gateway does) reads them from here and never has to patch either action.
     */
    protected function saved(PaymentMethod $method, Request $request): void
    {
        hook_action('checkout.payment_method.saved', ['method' => $method, 'request' => $request]);
    }

    /**
     * The method's logo: replaced by an upload, or cleared by the tick box —
     * exactly ShippingMethodsController::syncLogo(). singleFile() drops the old
     * file on its own, so an upload never needs the removal first; clearing is
     * its own answer, because a shop that stops showing a mark has no new file
     * to give.
     */
    protected function syncLogo(Request $request, PaymentMethod $method): void
    {
        if ($request->hasFile('logo')) {
            $this->images->addUpload($method, $request->file('logo'), 'logo');

            return;
        }

        if ($request->boolean('logo_remove')) {
            $method->clearMediaCollection('logo');
        }
    }

    public function destroy(PaymentMethod $paymentMethod)
    {
        if (($this->orderCounts()[$paymentMethod->id] ?? 0) > 0) {
            return back()->withErrors(__('Orders were paid by this method, so it cannot be deleted. Switch it off instead.'));
        }

        $paymentMethod->delete();

        return redirect()->route('admin.checkout.payment-methods.index')->with('status', __('Payment method deleted.'));
    }

    protected function data(Request $request, PaymentMethod $method): array
    {
        $validated = $request->validate([
            // In the admin's content language — see put_translation().
            'name' => [$method->exists && ! content_locale_is_base() ? 'nullable' : 'required', 'string'],
            'instructions' => 'nullable|string',
            'position' => 'nullable|integer',
            'currencies' => 'nullable|array',
            'currencies.*' => 'string|exists:currencies,code',
            // Must be one the method actually accepts: a method charging in a
            // currency it says it does not take is a contradiction the checkout
            // would then have to resolve at the customer's expense.
            'charge_currency' => ['nullable', 'string', Rule::in((array) $request->input('currencies', []))],
            // The mime list is the image pipeline's own (no SVG — script that
            // renders as a picture). Spelled out here so a refusal is a field
            // error, not the processor's exception.
            'deposit_type' => ['nullable', Rule::in(['', 'fixed', 'percent'])],
            'deposit_value' => ['nullable', 'numeric', 'min:0', $request->input('deposit_type') === 'percent' ? 'max:99' : 'max:100000000'],
            'logo' => ['nullable', 'image', 'mimetypes:'.implode(',', array_keys(ImageProcessor::ALLOWED_MIME))],
        ]);

        $instructions = put_translation($method->instructions, $validated['instructions'] ?? null);

        // "Any currency" is NULL, not the full list: a shop that later adds a
        // currency must not find its payment methods quietly excluding it.
        $limited = $request->boolean('limit_currencies');
        $currencies = array_values(array_unique($validated['currencies'] ?? []));

        return [
            'name' => put_translation($method->name, $validated['name'] ?? null),
            'instructions' => $instructions ?: null,
            'position' => (int) ($validated['position'] ?? 0),
            'enabled' => $request->boolean('enabled'),
            'currencies' => $limited && $currencies !== [] ? $currencies : null,
            'charge_currency' => $limited ? ($validated['charge_currency'] ?: null) : null,
            // Part online now, the rest on delivery (PLAN §40.10/1): an amount
            // in the books' minor units, or a whole percent. Nothing typed is
            // "the whole order online", which is every method until now.
            ...$this->deposit($validated),
        ];
    }

    /** @return array{deposit_type: string|null, deposit_value: int|null} */
    protected function deposit(array $validated): array
    {
        $type = $validated['deposit_type'] ?? null;
        $value = (float) ($validated['deposit_value'] ?? 0);

        if (! in_array($type, ['fixed', 'percent'], true) || $value <= 0) {
            return ['deposit_type' => null, 'deposit_value' => null];
        }

        return [
            'deposit_type' => $type,
            'deposit_value' => $type === 'fixed' ? (int) round($value * 100) : (int) round($value),
        ];
    }
}
