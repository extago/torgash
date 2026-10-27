<?php

namespace Torgash\Catalog\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Catalog\Models\PriceType;

class PriceTypesController extends Controller
{
    /**
     * The list, searched in every language and by code, narrowed by status —
     * the categories list's arrangement. A handful of rows, filtered in PHP.
     */
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = in_array($request->query('status'), ['enabled', 'disabled'], true) ? $request->query('status') : null;
        $all = PriceType::withCount('markupRules')->with('customerGroups')->orderBy('sort')->orderBy('id')->get();

        return view('torgash.catalog::admin.price-types.index', [
            'priceTypes' => $all
                ->when($q !== '', fn ($rows) => $rows->filter(fn (PriceType $t) => str_contains(
                    mb_strtolower(json_encode($t->name, JSON_UNESCAPED_UNICODE).' '.$t->code),
                    mb_strtolower($q),
                )))
                ->when($status, fn ($rows) => $rows->filter(fn (PriceType $t) => $t->enabled === ($status === 'enabled')))
                ->values(),
            'filters' => ['q' => $q, 'status' => $status],
            'statusCounts' => [
                '' => $all->count(),
                'enabled' => $all->where('enabled', true)->count(),
                'disabled' => $all->where('enabled', false)->count(),
            ],
        ]);
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, PriceType $priceType)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $priceType->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.catalog::admin.price-types._status', ['priceType' => $priceType]);
    }

    public function create()
    {
        return view('torgash.catalog::admin.price-types.form', [
            'priceType' => new PriceType,
            'customerGroups' => CustomerGroup::orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $priceType = PriceType::create($this->data($request, null));
        $priceType->customerGroups()->sync($request->input('customer_groups', []));

        return $this->afterSave($request, $priceType, __('Price type created.'));
    }

    public function edit(PriceType $priceType)
    {
        $priceType->load(['customerGroups', 'markupRules.categories', 'markupRules.featureValues']);

        // Neighbours in the list's own order.
        $ids = PriceType::orderBy('sort')->orderBy('id')->pluck('id')->all();
        $at = array_search($priceType->id, $ids, true);

        return view('torgash.catalog::admin.price-types.form', [
            'priceType' => $priceType,
            'prevType' => $at !== false && $at > 0 ? PriceType::find($ids[$at - 1]) : null,
            'nextType' => $at !== false && isset($ids[$at + 1]) ? PriceType::find($ids[$at + 1]) : null,
            'customerGroups' => CustomerGroup::orderBy('id')->get(),
        ]);
    }

    public function update(Request $request, PriceType $priceType)
    {
        $priceType->update($this->data($request, $priceType));
        $priceType->customerGroups()->sync($request->input('customer_groups', []));

        return $this->afterSave($request, $priceType, __('Price type saved.'));
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, PriceType $priceType, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.catalog.price-types.index')
            : redirect()->route('admin.catalog.price-types.edit', $priceType))->with('status', $message);
    }

    public function destroy(PriceType $priceType)
    {
        if ($priceType->is_base) {
            return back()->with('status', __('The base price type cannot be deleted.'));
        }

        $priceType->delete();

        return redirect()->route('admin.catalog.price-types.index')->with('status', __('Price type deleted.'));
    }

    protected function data(Request $request, ?PriceType $priceType): array
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            'name' => [Rule::requiredIf($isBaseLocale), 'nullable', 'string'],
            'code' => ['nullable', 'string', 'max:191', 'regex:/^[A-Z0-9_-]+$/i', Rule::unique('m_catalog_price_types', 'code')->ignore($priceType?->id)],
            'adjustment_percent' => 'nullable|integer|min:-100|max:1000',
            'requires_auth' => 'nullable|boolean',
            'sort' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
            'show_on_storefront' => 'nullable|boolean',
            'storefront_note' => 'nullable|string',
        ]);

        $name = $priceType?->name ?? [];
        if (trim($validated['name'] ?? '') === '') {
            unset($name[$locale]);
        } else {
            $name[$locale] = $validated['name'];
        }

        // A blank box is the model's answer, not this controller's — see
        // Support\AssignsCode.
        $attrs = [
            'name' => $name,
            'code' => trim($validated['code'] ?? '') ?: null,
            'sort' => $validated['sort'] ?? 0,
            'enabled' => $request->boolean('enabled'),
        ];

        // The base type's economics are fixed (it mirrors effectivePrice()),
        // so its adjustment/requires_auth are never accepted from the form.
        if (! $priceType?->is_base) {
            $attrs['adjustment_percent'] = $validated['adjustment_percent'] ?? null;
            $attrs['requires_auth'] = $request->boolean('requires_auth');
            $attrs['show_on_storefront'] = $request->boolean('show_on_storefront');

            $note = $priceType?->storefront_note ?? [];
            if (trim($validated['storefront_note'] ?? '') === '') {
                unset($note[$locale]);
            } else {
                $note[$locale] = $validated['storefront_note'];
            }
            $attrs['storefront_note'] = $note;
        }

        return $attrs;
    }
}
