<?php

namespace Torgash\Shipping\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Media\ImageProcessor;
use Torgash\Shipping\Conditions\Conditions;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\Zone;
use Torgash\Shipping\ShippingProviders;

class ShippingMethodsController extends Controller
{
    public function __construct(protected ShippingProviders $providers, protected ImageProcessor $images) {}

    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ['enabled', 'disabled'], true) ? (string) $request->query('status') : '',
        ];

        // rates.zone and conditions eagerly: the list shows where each method
        // is offered and what a cart must meet for it to appear — a fixed few
        // queries for the page, not per method. A shop has a handful, so the
        // search runs over the name in every language in PHP.
        $all = ShippingMethod::with('rates.zone', 'conditions', 'media')->orderBy('position')->orderBy('id')->get();
        $found = $filters['q'] === '' ? $all : $all->filter(fn (ShippingMethod $method) => collect((array) $method->name)
            ->contains(fn ($name) => mb_stripos((string) $name, $filters['q']) !== false));

        $methods = (match ($filters['status']) {
            'enabled' => $found->where('enabled', true),
            'disabled' => $found->where('enabled', false),
            default => $found,
        })->values();

        return view('torgash.shipping::admin.shipping_methods.index', [
            'methods' => $methods,
            'filters' => $filters,
            'statusCounts' => [
                '' => $found->count(),
                'enabled' => $found->where('enabled', true)->count(),
                'disabled' => $found->where('enabled', false)->count(),
            ],
            'providers' => $this->providers->options(),
            // What other modules have to say about each row (checkout: "this
            // one is used for orders in one click") — asked once for the list,
            // answered as id => html, the payment methods' own arrangement.
            'notes' => (array) hook_filter('shipping.methods.notes', [], ['methods' => $methods]),
        ]);
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, ShippingMethod $shippingMethod)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $shippingMethod->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.shipping::admin.shipping_methods._status', ['method' => $shippingMethod]);
    }

    /**
     * Several at once, through the models so the audit hears each one. Orders
     * keep the method's NAME, not its id, so deleting one takes nothing from
     * an order — its rates and conditions go with it (the keys cascade).
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $methods = ShippingMethod::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $methods->each->delete();

            return back()->with('status', trans_choice(':count shipping method deleted.|:count shipping methods deleted.', $methods->count(), ['count' => $methods->count()]));
        }

        $methods->each(fn (ShippingMethod $method) => $method->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count shipping method updated.|:count shipping methods updated.', $methods->count(), ['count' => $methods->count()]));
    }

    public function create()
    {
        return $this->form(new ShippingMethod);
    }

    public function store(Request $request)
    {
        $method = ShippingMethod::create($this->data($request, new ShippingMethod));
        $this->syncConditions($request, $method);
        $this->syncLogo($request, $method);
        $this->saveExtras($request, $method);

        // Straight to the zones tab: a method with no rate is offered nowhere,
        // so the tab that says so is the next step, not an afterthought.
        if ($request->input('after_save') === 'close') {
            return redirect()->route('admin.shipping.methods.index')->with('status', __('Shipping method created. Give it a rate in every zone you deliver to.'));
        }

        return redirect()->route('admin.shipping.methods.edit', [$method, 'tab' => 'rates'])
            ->with('status', __('Shipping method created. Give it a rate in every zone you deliver to.'));
    }

    public function edit(ShippingMethod $shippingMethod)
    {
        return $this->form($shippingMethod);
    }

    public function update(Request $request, ShippingMethod $shippingMethod)
    {
        $shippingMethod->update($this->data($request, $shippingMethod));
        $this->syncConditions($request, $shippingMethod);
        $this->syncLogo($request, $shippingMethod);
        $this->saveExtras($request, $shippingMethod);

        if ($request->input('after_save') === 'close') {
            return redirect()->route('admin.shipping.methods.index')->with('status', __('Shipping method updated.'));
        }

        // Back to the tab the save came from — a tabbed screen that jumps to
        // the first tab on every save makes the admin find their place again.
        return redirect()
            ->route('admin.shipping.methods.edit', [$shippingMethod, 'tab' => $this->tab($request)])
            ->with('status', __('Shipping method updated.'));
    }

    public function destroy(ShippingMethod $shippingMethod)
    {
        $shippingMethod->delete();

        return redirect()->route('admin.shipping.methods.index')->with('status', __('Shipping method deleted.'));
    }

    protected function form(ShippingMethod $method)
    {
        // The carrier section is rendered for the method's CURRENT provider:
        // switching the picker and saving is what brings a different one in.
        $current = $this->providers->get($method->provider);

        return view('torgash.shipping::admin.shipping_methods.form', [
            'method' => $method->loadMissing('conditions'),
            'providers' => $this->providers->options(),
            'providerConfigView' => $current?->configView(),
            'providerConfig' => old('provider_config', $method->provider_config ?? []),
            // Every zone, not only the priced ones: the tab answers "where is
            // this offered", and a zone with no rate is half that answer.
            'zones' => $method->exists
                ? Zone::orderBy('position')->orderBy('id')->get()
                : collect(),
            'rates' => $method->exists
                ? $method->rates()->with('rows')->get()->keyBy('zone_id')
                : collect(),
        ]);
    }

    /**
     * Whatever another module hangs off this method, saved by that module.
     *
     * The same pair as payment methods: a filter that puts fields on the form
     * (`shipping.method.extra`) and an action that lets whoever printed them
     * store what came back. It exists because a delivery method is the natural
     * home for facts that are not shipping's own — the 1C exchange needs the
     * identifier of the service item it should bill delivery to, and that is a
     * question about THIS method that neither module may answer by adding a
     * column to the other's table.
     */
    protected function saveExtras(Request $request, ShippingMethod $method): void
    {
        hook_action('shipping.method.saved', ['method' => $method, 'request' => $request]);
    }

    /**
     * The carrier's logo: replaced by an upload, or cleared by the tick box.
     *
     * singleFile() on the collection means media-library drops the old file on
     * its own, so an upload never needs the removal first. Clearing is its own
     * answer, though — a shop that stops using a carrier's mark has no new file
     * to give, and leaving the old one is not what "remove" means.
     */
    protected function syncLogo(Request $request, ShippingMethod $method): void
    {
        if ($request->hasFile('logo')) {
            $this->images->addUpload($method, $request->file('logo'), 'logo');

            return;
        }

        if ($request->boolean('logo_remove')) {
            $method->clearMediaCollection('logo');
        }
    }

    /**
     * Conditions are replaced wholesale, like rate bands and zone rules: a
     * handful of ordered rows carrying no state of their own, where a diff
     * costs more code than it saves.
     *
     * Unknown kinds and operators are dropped rather than stored: the value
     * shapes are per-kind, and a row nobody can evaluate would silently hide
     * the method (Conditions::pass refuses what it does not understand).
     */
    protected function syncConditions(Request $request, ShippingMethod $method): void
    {
        $method->conditions()->delete();
        $position = 0;

        foreach ((array) $request->input('conditions', []) as $row) {
            $kind = (string) ($row['kind'] ?? '');
            $operator = (string) ($row['operator'] ?? '');

            if (! isset(Conditions::KINDS[$kind][$operator])) {
                continue;
            }

            $method->conditions()->create([
                'kind' => $kind,
                'operator' => $operator,
                'value' => $this->conditionValue($kind, $row),
                'position' => $position++,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function conditionValue(string $kind, array $row): array
    {
        return match ($kind) {
            'category', 'customer_group' => [
                'ids' => array_values(array_filter(array_map('intval', (array) ($row['ids'] ?? [])))),
            ],
            'attribute' => array_filter([
                'key' => trim((string) ($row['key'] ?? '')),
                'match' => trim((string) ($row['match'] ?? '')),
            ], fn ($value) => $value !== ''),
            'coupon' => array_filter(['code' => trim((string) ($row['code'] ?? ''))], fn ($v) => $v !== ''),
            // Money in minor units here as everywhere, whatever the field said.
            'cart_total' => ['amount' => (int) round((float) str_replace(',', '.', (string) ($row['amount'] ?? 0)) * 100)],
            default => [],
        };
    }

    /** The tab the form was on, echoed back by a hidden field. */
    protected function tab(Request $request): string
    {
        $tab = (string) $request->input('tab', 'general');

        return in_array($tab, ['general', 'rates', 'conditions', 'carrier'], true) ? $tab : 'general';
    }

    protected function data(Request $request, ShippingMethod $method): array
    {
        $validated = $request->validate([
            // Written in the admin's content language; required where there
            // is nothing yet or where it is the base language every other
            // falls back to.
            'name' => [$method->exists && ! content_locale_is_base() ? 'nullable' : 'required', 'string'],
            'position' => 'nullable|integer',
            'provider' => ['nullable', Rule::in(array_keys($this->providers->all()))],
            'price_mode' => ['nullable', Rule::in(array_keys(ShippingMethod::MODES))],
            'on_incalculable' => ['nullable', Rule::in(array_keys(ShippingMethod::ON_INCALCULABLE))],
            'charge_on' => ['nullable', Rule::in(array_keys(ShippingMethod::CHARGE_ON))],
            'volumetric_divisor' => 'nullable|integer|min:1|max:65535',
            // The mime list is the image pipeline's own (no SVG — it is script
            // that renders as a picture). Spelling it out here turns a refusal
            // into a field error instead of the processor's exception.
            'logo' => ['nullable', 'image', 'mimetypes:'.implode(',', array_keys(ImageProcessor::ALLOWED_MIME))],
        ]);

        $provider = $this->providers->get($validated['provider'] ?? null);

        // "Ask the carrier" with no carrier named would offer a method that can
        // never produce a price — refused here rather than silently falling
        // back, because the admin picked the mode on purpose.
        if (($validated['price_mode'] ?? null) === 'carrier' && ! $provider) {
            throw ValidationException::withMessages([
                'price_mode' => __('Pick the carrier on the Carrier tab, or choose another way to price this method.'),
            ]);
        }

        // What it costs is not here: that is a property of the pair (method,
        // zone), edited under Rates — unless a provider is set, and then the
        // carrier prices it live from the config it normalises here.
        return [
            'name' => put_translation($method->name, $validated['name'] ?? null),
            'position' => (int) ($validated['position'] ?? 0),
            'provider' => ($validated['provider'] ?? null) ?: null,
            'price_mode' => $validated['price_mode'] ?? 'bands',
            'on_incalculable' => $validated['on_incalculable'] ?? 'quote',
            'charge_on' => $validated['charge_on'] ?? 'actual',
            'volumetric_divisor' => (int) ($validated['volumetric_divisor'] ?? 4000) ?: 4000,
            'provider_config' => $provider
                ? ($provider->normalizeConfig((array) $request->input('provider_config', [])) ?: null)
                : null,
            // Only a method with no carrier says it itself; a carrier answers
            // per variant (ProviderOffer::needsAddress).
            'needs_address' => $provider ? true : $request->boolean('needs_address', true),
            'enabled' => $request->boolean('enabled'),
        ];
    }
}
