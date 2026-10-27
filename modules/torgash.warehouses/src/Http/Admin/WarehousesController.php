<?php

namespace Torgash\Warehouses\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Torgash\Warehouses\Hours;
use Torgash\Warehouses\Models\Warehouse;

class WarehousesController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'status' => in_array($request->query('status'), ['enabled', 'disabled', 'pickup'], true) ? (string) $request->query('status') : '',
        ];

        // A shop has a handful: searched in PHP over the name in every
        // language and the address, as the shipping lists are.
        $all = Warehouse::orderBy('sort')->orderBy('id')->get();
        $found = $filters['q'] === '' ? $all : $all->filter(fn (Warehouse $warehouse) => collect((array) $warehouse->name)
            ->push((string) $warehouse->address)
            ->contains(fn ($text) => mb_stripos((string) $text, $filters['q']) !== false));

        $warehouses = (match ($filters['status']) {
            'enabled' => $found->where('enabled', true),
            'disabled' => $found->where('enabled', false),
            'pickup' => $found->where('is_pickup', true),
            default => $found,
        })->values();

        return view('torgash.warehouses::admin.index', [
            'warehouses' => $warehouses,
            'filters' => $filters,
            'statusCounts' => [
                '' => $found->count(),
                'enabled' => $found->where('enabled', true)->count(),
                'disabled' => $found->where('enabled', false)->count(),
                'pickup' => $found->where('is_pickup', true)->count(),
            ],
            'holdings' => $this->holdings(),
            'placeNames' => $this->placeNames($warehouses->pluck('place_id')->all()),
        ]);
    }

    /**
     * What every warehouse holds, in one GROUP BY: how many products have a
     * row here, how many of them actually have units, and the units.
     *
     * @return array<int, object{products: int, stocked: int, units: int}>
     */
    protected function holdings(): array
    {
        return DB::table('m_warehouse_product_stock')->groupBy('warehouse_id')
            ->selectRaw('warehouse_id, count(*) as products, count(*) filter (where qty <> 0) as stocked, coalesce(sum(qty), 0) as units')
            ->get()->keyBy('warehouse_id')->all();
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, Warehouse $warehouse)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $warehouse->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.warehouses::admin._status', ['warehouse' => $warehouse]);
    }

    /**
     * Several at once, through the models so the audit hears each one. A
     * warehouse that still holds units is not deleted — the same rule as one
     * at a time — and the answer names which stayed.
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $warehouses = Warehouse::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] !== 'delete') {
            $warehouses->each(fn (Warehouse $warehouse) => $warehouse->update(['enabled' => $validated['action'] === 'enable']));

            return back()->with('status', trans_choice(':count warehouse updated.|:count warehouses updated.', $warehouses->count(), ['count' => $warehouses->count()]));
        }

        [$kept, $gone] = $warehouses->partition(fn (Warehouse $warehouse) => $warehouse->stockedCount() > 0);
        $gone->each->delete();
        $this->repairDefault();

        $response = back()->with('status', trans_choice(':count warehouse deleted.|:count warehouses deleted.', $gone->count(), ['count' => $gone->count()]));

        return $kept->isEmpty() ? $response : $response->withErrors(__('These still hold stock, so they stay — move or clear it first: :names.', [
            'names' => $kept->map(fn (Warehouse $warehouse) => $warehouse->t('name'))->implode(', '),
        ]));
    }

    public function create()
    {
        return view('torgash.warehouses::admin.form', [
            'warehouse' => new Warehouse,
            'placeName' => '',
        ]);
    }

    public function store(Request $request)
    {
        $warehouse = Warehouse::create($this->data($request));
        $this->syncDefault($warehouse);

        return $this->saved($request, $warehouse, __('Warehouse created.'));
    }

    public function edit(Warehouse $warehouse)
    {
        return view('torgash.warehouses::admin.form', [
            'warehouse' => $warehouse,
            'placeName' => $this->placeNames([$warehouse->place_id])[$warehouse->place_id] ?? '',
            'holding' => $this->holdings()[$warehouse->id] ?? null,
        ]);
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $warehouse->update($this->data($request, $warehouse));
        $this->syncDefault($warehouse);

        return $this->saved($request, $warehouse, __('Warehouse updated.'));
    }

    /** Saving stays on the form; "Save and close" in the gear goes back to the list. */
    protected function saved(Request $request, Warehouse $warehouse, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.warehouses.index')
            : redirect()->route('admin.warehouses.edit', $warehouse))->with('status', $message);
    }

    /**
     * Blocked while it still holds units of any product — deleting it would
     * either silently drop them or force a guess about where they went. Move
     * the stock (the per-warehouse screen) or zero it out first. Rows at zero
     * are not stock: they go with the warehouse.
     */
    public function destroy(Warehouse $warehouse)
    {
        if (($stocked = $warehouse->stockedCount()) > 0) {
            return back()->withErrors(['warehouse' => __('This warehouse still holds stock for :n products — move or clear it first.', ['n' => $stocked])]);
        }

        $warehouse->delete();
        $this->repairDefault();

        return redirect()->route('admin.warehouses.index')->with('status', __('Warehouse deleted.'));
    }

    protected function data(Request $request, ?Warehouse $warehouse = null): array
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            'name' => [Rule::requiredIf($isBaseLocale), 'nullable', 'string'],
            'address' => 'nullable|string|max:255',
            'place_id' => 'nullable|integer',
            'phone' => 'nullable|string|max:64',
            'transfer_days' => 'nullable|integer|min:0|max:365',
            'sort' => 'nullable|integer',
        ]);

        $name = $warehouse?->name ?? [];
        if (trim($validated['name'] ?? '') === '') {
            unset($name[$locale]);
        } else {
            $name[$locale] = $validated['name'];
        }

        $isPickup = $request->boolean('is_pickup');

        return [
            'name' => $name,
            'address' => ($validated['address'] ?? null) ?: null,
            'place_id' => ((int) ($validated['place_id'] ?? 0)) ?: null,
            'phone' => ($validated['phone'] ?? null) ?: null,
            // Empty is "does not send to pickup points" — not zero days.
            'transfer_days' => ($validated['transfer_days'] ?? null) === null || $validated['transfer_days'] === '' ? null : (int) $validated['transfer_days'],
            'hours' => Hours::fromInput((array) $request->input('hours', [])),
            'sort' => (int) ($validated['sort'] ?? 0),
            'is_default' => $request->boolean('is_default'),
            'enabled' => $request->boolean('enabled'),
            // A point an order can be collected from is by definition a point
            // the customer is told about: the picker reads is_public, so the
            // other combination would be a pickup point nobody can pick.
            'is_public' => $request->boolean('is_public') || $isPickup,
            'is_pickup' => $isPickup,
        ];
    }

    /**
     * Town names for the ids stored on warehouses — one query for the screen,
     * and nothing at all when no place registry is installed.
     *
     * @param  list<int|null>  $ids
     * @return array<int, string>
     */
    protected function placeNames(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $ids === [] ? [] : (array) hook_filter('locations.place.names', [], ['ids' => $ids]);
    }

    /** After a delete: the default may have been the one that went. */
    protected function repairDefault(): void
    {
        if (! Warehouse::where('is_default', true)->exists()) {
            Warehouse::orderBy('sort')->orderBy('id')->first()?->update(['is_default' => true]);
        }
    }

    /** Exactly one default at a time — the fallback destination for stock returns and untargeted restocks. */
    protected function syncDefault(Warehouse $warehouse): void
    {
        if ($warehouse->is_default) {
            Warehouse::where('id', '!=', $warehouse->id)->update(['is_default' => false]);

            return;
        }

        if (! Warehouse::where('is_default', true)->exists()) {
            Warehouse::orderBy('sort')->first()?->update(['is_default' => true]);
        }
    }
}
