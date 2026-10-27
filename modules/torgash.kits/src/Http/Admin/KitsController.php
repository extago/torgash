<?php

namespace Torgash\Kits\Http\Admin;

use App\Core\Display\DisplayRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Pricing\Prices;
use Torgash\Catalog\Selections\Sources;
use Torgash\Kits\Kits;
use Torgash\Kits\Models\Kit;
use Torgash\Kits\Models\KitSlot;

/**
 * Kits and their slots (PLAN §33.5).
 *
 * One screen for both kinds, as the options module has: a kit with a main
 * product belongs to it and is reached from its form, a kit without one is
 * placed by display rules. The slots are edited WITH the kit — a slot on its
 * own means nothing, and a kit with no slots is not a kit.
 */
class KitsController extends Controller
{
    /**
     * The list, searched by either name in any language and by the main
     * product (SKU, name), narrowed by whose the kit is and by status, paged —
     * the option groups list's arrangement.
     */
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'whose' => in_array($request->query('whose'), ['shared', 'product'], true) ? $request->query('whose') : null,
            'status' => in_array($request->query('status'), ['enabled', 'disabled'], true) ? $request->query('status') : null,
        ];
        // What the admin typed, as a substring: % and _ are theirs, not wildcards.
        $like = '%'.addcslashes($filters['q'], '%_\\').'%';

        $counts = Kit::query()
            ->selectRaw('count(*) as all_n')
            ->selectRaw('count(*) filter (where main_product_id is null) as shared_n')
            ->selectRaw('count(*) filter (where main_product_id is not null) as product_n')
            ->selectRaw('count(*) filter (where enabled) as enabled_n')
            ->selectRaw('count(*) filter (where not enabled) as disabled_n')
            ->first();

        return view('torgash.kits::admin.kits.index', [
            'kits' => Kit::query()
                ->with('mainProduct')->withCount('slots')
                ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                    ->where('admin_name', 'ilike', $like)
                    ->orWhereRaw('name::text ilike ?', [$like])
                    ->orWhereHas('mainProduct', fn ($p) => $p->where('sku', 'ilike', $like)->orWhereRaw('name::text ilike ?', [$like]))))
                ->when($filters['whose'] === 'shared', fn ($q) => $q->whereNull('main_product_id'))
                ->when($filters['whose'] === 'product', fn ($q) => $q->whereNotNull('main_product_id'))
                ->when($filters['status'], fn ($q, $s) => $q->where('enabled', $s === 'enabled'))
                ->orderByRaw('main_product_id nulls first')
                ->orderBy('sort')->orderBy('id')
                ->paginate(50)->withQueryString(),
            'filters' => $filters,
            'counts' => $counts,
        ]);
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, Kit $kit)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $kit->update(['enabled' => $request->boolean('enabled')]);
        app(Kits::class)->flush();

        return view('torgash.kits::admin.kits._status', ['kit' => $kit]);
    }

    /** Several at once — through the models, so the audit hears each one. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $kits = Kit::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $kits->each->delete();
            $message = trans_choice(':count kit deleted.|:count kits deleted.', $kits->count(), ['count' => $kits->count()]);
        } else {
            $kits->each(fn (Kit $k) => $k->update(['enabled' => $validated['action'] === 'enable']));
            $message = trans_choice(':count kit updated.|:count kits updated.', $kits->count(), ['count' => $kits->count()]);
        }

        app(Kits::class)->flush();

        return redirect()->back()->with('status', $message);
    }

    public function create(Request $request)
    {
        $productId = (int) $request->query('product', 0);

        return $this->form(new Kit([
            // A kit is a row of products; the full-width zone is the only
            // one that actually fits one.
            'placement' => 'before_details',
            'layout' => 'grid',
            'enabled' => true,
            'type' => 'extended',
            'sort' => 0,
            'rules_match' => DisplayRules::MATCH_ALL,
            'main_product_id' => Product::whereKey($productId)->value('id'),
        ]));
    }

    public function store(Request $request)
    {
        $kit = Kit::create($this->data($request, null));
        $this->saveSlots($request, $kit);
        app(Kits::class)->flush();

        return $this->afterSave($request, $kit, __('Kit created.'));
    }

    public function edit(Kit $kit)
    {
        $kit->load('slots');

        return $this->form($kit);
    }

    public function update(Request $request, Kit $kit)
    {
        $kit->update($this->data($request, $kit));
        $this->saveSlots($request, $kit);
        app(Kits::class)->flush();

        return $this->afterSave($request, $kit, __('Kit saved.'));
    }

    /** Back to the form; "Save and close" returns to wherever the kit is listed. */
    protected function afterSave(Request $request, Kit $kit, string $message)
    {
        if ($request->input('after_save') === 'close') {
            return ($kit->mainProduct
                ? redirect()->route('admin.catalog.products.edit', $kit->mainProduct)
                : redirect()->route('admin.kits.index'))->with('status', $message);
        }

        return redirect()->route('admin.kits.edit', $kit)->with('status', $message);
    }

    public function destroy(Kit $kit)
    {
        $product = $kit->mainProduct;
        $kit->delete();
        app(Kits::class)->flush();

        // A cart line bought in a kit that no longer exists is charged full
        // price from the next time the cart is priced — nothing is stored on
        // the line but which kit it was, so there is nothing to clean up.
        //
        // Deleted from the list: stay on the list (its page and filters).
        $previous = url()->previous();
        if (str_contains($previous, '/admin/kits') && ! str_contains($previous, '/edit')) {
            return redirect()->to($previous)->with('status', __('Kit deleted.'));
        }

        return $product
            ? redirect()->route('admin.catalog.products.edit', $product)->with('status', __('Kit deleted.'))
            : redirect()->route('admin.kits.index')->with('status', __('Kit deleted.'));
    }

    protected function form(Kit $kit)
    {
        return view('torgash.kits::admin.kits.form', [
            'kit' => $kit,
            // "Picked by hand" has no argument outside a block's own settings,
            // so it would stand for an empty set here — the options module
            // leaves it out for the same reason.
            'sourceOptions' => collect(app(Sources::class)->options())
                ->reject(fn (array $option) => str_starts_with($option['value'], 'manual'))
                ->values()->all(),
            'features' => Feature::orderBy('sort')->get(),
            'currencies' => app(\App\Core\Support\Currencies::class)->all(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function data(Request $request, ?Kit $kit): array
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            'name' => [Rule::requiredIf($isBaseLocale), 'nullable', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:2000'],
            'admin_name' => ['required', 'string', 'max:190'],
            'main_product_id' => ['nullable', 'integer', 'exists:m_catalog_products,id'],
            'placement' => ['required', Rule::in(Kit::PLACEMENTS)],
            'layout' => ['required', Rule::in(Kit::LAYOUTS)],
            'type' => ['required', Rule::in(Kit::TYPES)],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'sort' => ['nullable', 'integer'],
        ]);

        return [
            'name' => $this->translatable($kit?->name, $validated['name'] ?? '', $locale),
            'description' => $this->translatable($kit?->description, $validated['description'] ?? '', $locale) ?: null,
            'admin_name' => $validated['admin_name'],
            'main_product_id' => $validated['main_product_id'] ?? null,
            'placement' => $validated['placement'],
            'layout' => $validated['layout'],
            'type' => $validated['type'],
            'starts_at' => $validated['starts_at'] ?? null,
            'ends_at' => $validated['ends_at'] ?? null,
            'sort' => (int) ($validated['sort'] ?? 0),
            'enabled' => $request->boolean('enabled'),
            'whole_set_only' => $request->boolean('whole_set_only'),
            // A kit belonging to a product goes where that product goes; only a
            // shared one is placed by rules.
            'rules' => ($validated['main_product_id'] ?? null)
                ? null
                : app(DisplayRules::class)->normalise($request->input('rules', [])),
            'rules_match' => $request->input('rules_match') === DisplayRules::MATCH_ANY
                ? DisplayRules::MATCH_ANY
                : DisplayRules::MATCH_ALL,
        ];
    }

    /**
     * The slots, rewritten from the form.
     *
     * Matched by id so an edit keeps the slot a cart line was bought in; a row
     * with no product and no set is unfinished and dropped rather than
     * refusing the whole form. Money goes through `Prices`, the one place a
     * typed amount and a currency become the books' own column.
     */
    protected function saveSlots(Request $request, Kit $kit): void
    {
        $prices = app(Prices::class);
        $kept = [];
        $sort = 0;

        foreach ((array) $request->input('slots', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kind = in_array($row['kind'] ?? '', KitSlot::KINDS, true) ? $row['kind'] : 'product';
            $product = $kind === 'product' ? $this->productFor((string) ($row['product'] ?? '')) : null;
            $source = $kind === 'source' ? trim((string) ($row['source'] ?? '')) : '';

            if ($kind === 'product' && ! $product) {
                continue;
            }

            if ($kind === 'source' && $source === '') {
                continue;
            }

            $slot = isset($row['id']) && (int) $row['id'] > 0
                ? $kit->slots()->whereKey((int) $row['id'])->first() ?? new KitSlot(['kit_id' => $kit->id])
                : new KitSlot(['kit_id' => $kit->id]);

            $money = $prices->row($row['discount_amount'] ?? null, $row['discount_currency'] ?? null);

            $slot->fill([
                'kit_id' => $kit->id,
                'kind' => $kind,
                'product_id' => $product?->id,
                'source' => $kind === 'source' ? $source : null,
                // A list, and an empty one is "anything in the set" rather
                // than null-the-column: the accessor reads both the same way.
                'match_features' => array_values(array_filter(array_map('intval', (array) ($row['match_features'] ?? [])))),
                'qty' => max(1, (int) ($row['qty'] ?? 1)),
                'discount_type' => in_array($row['discount_type'] ?? '', KitSlot::DISCOUNTS, true) ? $row['discount_type'] : 'percent',
                'discount_percent' => ($row['discount_percent'] ?? '') === '' ? null : (float) str_replace(',', '.', (string) $row['discount_percent']),
                'discount_amount' => $money['price'],
                'discount_amount_original' => $money['price_original'],
                'discount_currency' => $money['price_original'] !== null ? strtoupper((string) $row['discount_currency']) : null,
                'required' => ! empty($row['required']),
                'swappable' => ! isset($row['swappable']) || (bool) $row['swappable'],
                'sort' => $sort += 10,
                'enabled' => ! isset($row['enabled']) || (bool) $row['enabled'],
            ])->save();

            $kept[] = $slot->id;
        }

        $kit->slots()->whereNotIn('id', $kept ?: [0])->delete();
    }

    /** A product named by id or by SKU — what an admin has in front of them. */
    protected function productFor(string $value): ?Product
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        return Product::query()
            ->where(fn ($q) => $q
                ->where('id', is_numeric($value) ? (int) $value : 0)
                ->orWhere('sku', $value))
            ->first();
    }

    /** Writes into $existing[$locale], removing the key when blank. */
    protected function translatable(?array $existing, string $incoming, string $locale): array
    {
        // Core's one rule: blank removes this language, the others stay.
        return put_translation($existing, $incoming, $locale);
    }
}
