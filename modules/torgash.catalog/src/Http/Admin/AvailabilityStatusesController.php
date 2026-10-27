<?php

namespace Torgash\Catalog\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Models\AvailabilityStatus;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Stock\AvailabilityStatuses;

/**
 * Where a shop finds its availability statuses (PLAN §31.6).
 *
 * Every status is listed — the five the catalogue registers, a module's own,
 * the shop's own — because "where do I configure preorder" is answered by
 * seeing it. What can be changed depends on whose status it is: a status from
 * code may be renamed and weighed and nothing else (its meaning is what that
 * code relies on); the shop's own may be edited entirely and deleted while no
 * product carries it.
 *
 * The route parameter is the status KEY, never a row id: a code status the
 * shop has not touched has no row, and must be editable all the same.
 */
class AvailabilityStatusesController extends Controller
{
    /** schema.org's ItemAvailability, in the order a person reads them. */
    public const SCHEMA = [
        'InStock', 'OutOfStock', 'PreOrder', 'PreSale', 'BackOrder', 'MadeToOrder',
        'LimitedAvailability', 'OnlineOnly', 'InStoreOnly', 'SoldOut', 'Discontinued', 'Reserved',
    ];

    public function index(Request $request, AvailabilityStatuses $statuses)
    {
        $all = $statuses->all();
        $q = mb_strtolower(trim((string) $request->query('q', '')));

        // The trash is not the catalogue: a deleted product carries no weight
        // in any listing, and counting it would refuse a delete for no reason.
        $counts = Product::query()
            ->selectRaw('availability_status, count(*) as n')
            ->groupBy('availability_status')
            ->pluck('n', 'availability_status')
            ->map(fn ($n) => (int) $n)
            ->all();

        $rows = [];
        $order = 0;

        foreach ($all as $key => $def) {
            $rows[] = $def + ['key' => $key, 'name' => $statuses->label($key), 'products' => $counts[$key] ?? 0, 'order' => $order++];
        }

        // Heaviest first — the order a listing will show them in — and
        // registration order within one weight, so the screen is stable.
        usort($rows, fn ($a, $b) => [$b['sort_weight'], $a['order']] <=> [$a['sort_weight'], $b['order']]);

        // Searched by the name on screen and by the code a theme or an import uses.
        if ($q !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => str_contains(mb_strtolower($r['name'].' '.$r['key']), $q)));
        }

        return view('torgash.catalog::admin.availability-statuses.index', [
            'rows' => $rows,
            // Products left carrying a status nobody answers for any more (its
            // module uninstalled, a row deleted by hand): they cannot be bought
            // and rank last, and this is the only place that says so.
            'orphans' => array_diff_key($counts, $all),
            'rankingOn' => (bool) setting('torgash.catalog', 'out_of_stock_last', false),
            'filters' => ['q' => trim((string) $request->query('q', ''))],
        ]);
    }

    /** One form for every weight: comparing numbers is done by looking at all of them at once. */
    public function weights(Request $request, AvailabilityStatuses $statuses)
    {
        $data = $request->validate([
            'weights' => ['array'],
            'weights.*' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
        ]);

        foreach (array_keys($statuses->all()) as $key) {
            if (! array_key_exists($key, $data['weights'] ?? [])) {
                continue;
            }

            $weight = $data['weights'][$key];
            $row = AvailabilityStatus::firstOrNew(['key' => $key]);
            $row->sort_weight = $weight === null ? null : (int) $weight;

            $this->persist($row, $statuses);
        }

        $statuses->flush();

        return redirect()->route('admin.catalog.availability-statuses.index')->with('status', __('Weights saved.'));
    }

    public function create()
    {
        return $this->form('', [
            'label' => [],
            'orderable' => true,
            'tracks_stock' => true,
            'allow_negative' => false,
            'schema' => 'https://schema.org/InStock',
            'sort_weight' => AvailabilityStatuses::ORDERABLE_WEIGHT,
            'weighed' => false,
            'source' => 'shop',
            'renamed' => false,
        ], null);
    }

    public function store(Request $request, AvailabilityStatuses $statuses)
    {
        $locale = admin_content_locale();

        $data = $request->validate($this->rules(shop: true) + [
            'key' => ['nullable', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
        ]);

        $key = ($data['key'] ?? null) ?: Str::slug((string) $data['label'], '_');

        if ($key === '' || $statuses->find($key) || in_array($key, AvailabilityStatuses::RESERVED_KEYS, true)
            || AvailabilityStatus::where('key', $key)->exists()) {
            throw ValidationException::withMessages([
                'key' => __('The code “:key” is already taken — type another one.', ['key' => $key ?: '—']),
            ]);
        }

        $row = new AvailabilityStatus(['key' => $key]);
        $this->fill($row, $data, $locale, shop: true);
        $row->save();
        $statuses->flush();

        return $this->afterSave($request, $key, __('Status created.'));
    }

    public function edit(string $status, AvailabilityStatuses $statuses)
    {
        $def = $statuses->find($status) ?? abort(404);

        return $this->form($status, $def, AvailabilityStatus::where('key', $status)->first());
    }

    public function update(Request $request, string $status, AvailabilityStatuses $statuses)
    {
        $def = $statuses->find($status) ?? abort(404);
        $shop = $def['source'] === 'shop';

        $data = $request->validate($this->rules($shop));

        $row = AvailabilityStatus::firstOrNew(['key' => $status]);
        $this->fill($row, $data, admin_content_locale(), $shop);
        $this->persist($row, $statuses);
        $statuses->flush();

        return $this->afterSave($request, $status, __('Status saved.'));
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, string $key, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.catalog.availability-statuses.index')
            : redirect()->route('admin.catalog.availability-statuses.edit', $key))->with('status', $message);
    }

    public function destroy(string $status, AvailabilityStatuses $statuses)
    {
        $def = $statuses->find($status) ?? abort(404);

        if ($def['source'] !== 'shop') {
            return back()->with('error', __('A status registered by a module cannot be deleted here — only renamed and weighed.'));
        }

        // Refused rather than cascaded: a product left with a deleted status
        // can no longer be bought, and moving hundreds of products to "some
        // other" status is a decision, not a side effect of tidying a list.
        $used = Product::where('availability_status', $status)->count();

        if ($used > 0) {
            return back()->with('error', __('This status is set on :n products. Give them another status first.', ['n' => $used]));
        }

        AvailabilityStatus::where('key', $status)->first()?->delete();
        $statuses->flush();

        return redirect()->route('admin.catalog.availability-statuses.index')->with('status', __('Status deleted.'));
    }

    protected function form(string $key, array $def, ?AvailabilityStatus $row)
    {
        return view('torgash.catalog::admin.availability-statuses.form', [
            'key' => $key,
            'def' => $def,
            // What the shop TYPED, for the translatable field — a code status's
            // own name is the placeholder, not a value to be saved back.
            'typed' => (array) ($row?->label ?? []),
            'typedWeight' => $row?->sort_weight,
            'schemaOptions' => array_map(fn ($name) => 'https://schema.org/'.$name, self::SCHEMA),
            'products' => $key === '' ? 0 : Product::where('availability_status', $key)->count(),
        ]);
    }

    protected function rules(bool $shop): array
    {
        $rules = [
            'label' => [$shop ? 'required' : 'nullable', 'string', 'max:190'],
            'sort_weight' => ['nullable', 'integer', 'min:-1000', 'max:1000'],
        ];

        if ($shop) {
            $rules += [
                'orderable' => ['nullable', 'boolean'],
                'tracks_stock' => ['nullable', 'boolean'],
                'allow_negative' => ['nullable', 'boolean'],
                'schema' => ['nullable', Rule::in(array_map(fn ($name) => 'https://schema.org/'.$name, self::SCHEMA))],
            ];
        }

        return $rules;
    }

    protected function fill(AvailabilityStatus $row, array $data, string $locale, bool $shop): void
    {
        $label = (array) ($row->label ?? []);
        $typed = trim((string) ($data['label'] ?? ''));

        if ($typed === '') {
            unset($label[$locale]);
        } else {
            $label[$locale] = $typed;
        }

        $row->label = $label ?: null;
        $row->sort_weight = isset($data['sort_weight']) ? (int) $data['sort_weight'] : null;

        if ($shop) {
            $row->orderable = (bool) ($data['orderable'] ?? false);
            $row->tracks_stock = (bool) ($data['tracks_stock'] ?? false);
            // Only meaningful for a number that is counted at all.
            $row->allow_negative = $row->tracks_stock && (bool) ($data['allow_negative'] ?? false);
            $row->schema = $data['schema'] ?? null;
        }
    }

    /**
     * A row for a code status that no longer says anything — no name of the
     * shop's, no weight — is deleted rather than kept empty: "not stated" is
     * the absence of a row, and a table of blank overrides is noise the next
     * reader has to reason about.
     */
    protected function persist(AvailabilityStatus $row, AvailabilityStatuses $statuses): void
    {
        $codeStatus = ($statuses->find($row->key)['source'] ?? 'shop') === 'code';

        if ($codeStatus && $row->sort_weight === null && empty($row->label)) {
            if ($row->exists) {
                $row->delete();
            }

            return;
        }

        $row->save();
    }
}
