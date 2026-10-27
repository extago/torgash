<?php

namespace Torgash\Taxes\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Torgash\Taxes\Models\Tax;
use Torgash\Taxes\Models\TaxRate;

class TaxesController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ['enabled', 'disabled'], true) ? $request->query('status') : '',
        ];

        // A shop has a handful of taxes, so the search runs over what the admin
        // can SEE — the name in every language and the code — in PHP.
        $all = Tax::withCount('rates')->orderBy('position')->orderBy('id')->get();
        $found = $filters['q'] === '' ? $all : $all->filter(fn (Tax $tax) => mb_stripos($tax->code, $filters['q']) !== false
            || collect((array) $tax->name)->contains(fn ($name) => mb_stripos((string) $name, $filters['q']) !== false));

        return view('torgash.taxes::admin.index', [
            'taxes' => match ($filters['status']) {
                'enabled' => $found->where('enabled', true)->values(),
                'disabled' => $found->where('enabled', false)->values(),
                default => $found->values(),
            },
            'filters' => $filters,
            'statusCounts' => [
                '' => $found->count(),
                'enabled' => $found->where('enabled', true)->count(),
                'disabled' => $found->where('enabled', false)->count(),
            ],
            'usage' => DB::table('m_taxes_product_tax')
                ->selectRaw('tax_id, count(*) as products')
                ->groupBy('tax_id')
                ->pluck('products', 'tax_id'),
            // How many orders charged it — what deleting it would NOT touch
            // (each order keeps its own copy), said on the row so nobody is
            // afraid of the trash can for the wrong reason.
            'orderCounts' => DB::table('m_taxes_order_taxes')
                ->whereNotNull('tax_id')
                ->selectRaw('tax_id, count(distinct order_id) as orders')
                ->groupBy('tax_id')
                ->pluck('orders', 'tax_id'),
        ]);
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, Tax $tax)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $tax->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.taxes::admin._status', ['tax' => $tax]);
    }

    /** Several at once, through the models so the audit hears each one. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $taxes = Tax::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $taxes->each->delete();

            return back()->with('status', trans_choice(':count tax deleted.|:count taxes deleted.', $taxes->count(), ['count' => $taxes->count()]));
        }

        $taxes->each(fn (Tax $tax) => $tax->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count tax updated.|:count taxes updated.', $taxes->count(), ['count' => $taxes->count()]));
    }

    public function create()
    {
        return view('torgash.taxes::admin.form', $this->formData(new Tax(['rate_type' => Tax::PERCENT, 'price_includes_tax' => true])));
    }

    public function store(Request $request)
    {
        $tax = DB::transaction(function () use ($request) {
            $tax = Tax::create($this->data($request));
            $this->syncRates($request, $tax);

            return $tax;
        });

        return $this->afterSave($request, $tax, __('Tax created.'));
    }

    public function edit(Tax $tax)
    {
        return view('torgash.taxes::admin.form', $this->formData($tax));
    }

    public function update(Request $request, Tax $tax)
    {
        DB::transaction(function () use ($request, $tax) {
            $tax->update($this->data($request, $tax));
            $this->syncRates($request, $tax);
        });

        return $this->afterSave($request, $tax, __('Tax saved.'));
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, Tax $tax, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.taxes.index')
            : redirect()->route('admin.taxes.edit', $tax))->with('status', $message);
    }

    /**
     * Orders keep their own copy of what they charged, so deleting a tax never
     * rewrites history — the snapshot rows just lose their link back.
     */
    public function destroy(Tax $tax)
    {
        $tax->delete();

        return redirect()->route('admin.taxes.index')->with('status', __('Tax deleted.'));
    }

    protected function data(Request $request, ?Tax $tax = null): array
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', Rule::unique('m_taxes_taxes', 'code')->ignore($tax?->id)],
            'name' => [Rule::requiredIf($isBaseLocale), 'nullable', 'string', 'max:190'],
            'rate_type' => ['required', Rule::in([Tax::PERCENT, Tax::FIXED])],
            'rate_percent' => 'nullable|numeric|min:0|max:100',
            'rate_amount' => 'nullable|numeric|min:0',
            'priority' => 'nullable|integer|min:0|max:100',
            'reg_number' => 'nullable|string|max:190',
            'position' => 'nullable|integer',
            'rates' => 'nullable|array|max:500',
            'rates.*.kind' => ['required', Rule::in(TaxRate::KINDS)],
            'rates.*.country' => 'nullable|string|size:2',
            'rates.*.state' => 'nullable|string|max:64|regex:/^[A-Za-z]{2}:.+$/',
            'rates.*.postal' => 'nullable|string|max:64',
            'rates.*.rate' => 'nullable|numeric|min:0|max:1000000',
        ]);

        $name = $tax?->name ?? [];
        if (trim($validated['name'] ?? '') === '') {
            unset($name[$locale]);
        } else {
            $name[$locale] = $validated['name'];
        }

        return [
            'code' => $validated['code'],
            'name' => $name,
            'rate_type' => $validated['rate_type'],
            'rate_percent' => $validated['rate_type'] === Tax::PERCENT ? (float) ($validated['rate_percent'] ?? 0) : null,
            // Entered in major units like every other money field in the admin.
            'rate_amount' => $validated['rate_type'] === Tax::FIXED
                ? (int) round((float) ($validated['rate_amount'] ?? 0) * 100)
                : null,
            'price_includes_tax' => $request->boolean('price_includes_tax'),
            'priority' => (int) ($validated['priority'] ?? 0),
            'applies_by_default' => $request->boolean('applies_by_default'),
            'applies_to_shipping' => $request->boolean('applies_to_shipping'),
            'only_listed' => $request->boolean('only_listed'),
            'reg_number' => ($validated['reg_number'] ?? null) ?: null,
            'enabled' => $request->boolean('enabled'),
            'position' => (int) ($validated['position'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    protected function formData(Tax $tax): array
    {
        return [
            'tax' => $tax,
            'rates' => $tax->exists ? $tax->rates()->get() : collect(),
            // Checkout's own questions, answered by the locations registry when
            // it is installed — empty without it, and the form then takes a
            // typed code instead of a list.
            'countries' => (array) hook_filter('checkout.location.countries', []),
            'regions' => (array) hook_filter('checkout.location.regions', []),
        ];
    }

    /**
     * Replaced wholesale, like a shipping zone's rules: a handful of rows that
     * carry nothing worth diffing. A row naming nothing is dropped rather than
     * refused (it is the empty row the form always offers), and a place named
     * twice keeps the first — the unique index would otherwise refuse the
     * whole form over a duplicate the admin did not notice.
     */
    protected function syncRates(Request $request, Tax $tax): void
    {
        $tax->rates()->delete();

        $seen = [];

        foreach (array_values((array) $request->input('rates', [])) as $position => $row) {
            $kind = (string) ($row['kind'] ?? '');
            $value = match ($kind) {
                'country' => strtoupper(trim((string) ($row['country'] ?? ''))),
                'state' => (function () use ($row) {
                    $raw = trim((string) ($row['state'] ?? ''));

                    if (! str_contains($raw, ':')) {
                        return '';
                    }

                    [$country, $code] = explode(':', $raw, 2);

                    return strtoupper(trim($country)).':'.trim($code);
                })(),
                'postal' => strtoupper(str_replace(' ', '', trim((string) ($row['postal'] ?? '')))),
                default => '',
            };

            if ($value === '' || isset($seen[$kind.'|'.$value])) {
                continue;
            }

            $seen[$kind.'|'.$value] = true;

            $charged = ($row['charged'] ?? '1') === '1';
            $rate = (float) ($row['rate'] ?? 0);

            $tax->rates()->create([
                'kind' => $kind,
                'value' => $value,
                'charged' => $charged,
                'rate_percent' => $charged && $tax->rate_type === Tax::PERCENT ? min(100, $rate) : null,
                // Typed in major units, like the tax's own fixed amount.
                'rate_amount' => $charged && $tax->rate_type === Tax::FIXED ? (int) round($rate * 100) : null,
                'position' => $position,
            ]);
        }
    }
}
