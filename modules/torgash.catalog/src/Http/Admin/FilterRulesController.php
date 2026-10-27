<?php

namespace Torgash\Catalog\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Jobs\GenerateFilterPages;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\FilterRule;
use Torgash\Catalog\Support\CategoryTree;

/**
 * "A page per make, in these categories" — or "a page per town" — and the
 * button that applies it.
 *
 * Deleting a rule does NOT delete the pages it made: they are published
 * addresses by then, and the link on each page survives precisely so a later
 * run recognises its own work. The rows are left, the `rule_id` goes null, and
 * from that moment they are ordinary hand-written pages — which is the only
 * answer that does not 404 a crawler over a change to a generator.
 */
class FilterRulesController extends Controller
{
    public function index(Request $request)
    {
        $filters = [
            'q' => trim((string) $request->query('q', '')),
            'tab' => in_array($request->query('tab'), ['on', 'off'], true) ? (string) $request->query('tab') : '',
        ];

        // A shop has a handful of rules: read once, searched by name, counted
        // per tab from the same collection.
        $all = FilterRule::with('feature')->withCount('pages')->orderBy('position')->orderBy('id')->get();
        $searched = $filters['q'] === '' ? $all : $all->filter(fn (FilterRule $rule) => mb_stripos($rule->name, $filters['q']) !== false);

        return view('torgash.catalog::admin.filter-rules.index', [
            'rules' => match ($filters['tab']) {
                'on' => $searched->where('enabled', true)->values(),
                'off' => $searched->where('enabled', false)->values(),
                default => $searched->values(),
            },
            'filters' => $filters,
            'counts' => [
                '' => $searched->count(),
                'on' => $searched->where('enabled', true)->count(),
                'off' => $searched->where('enabled', false)->count(),
            ],
            // The categories by their own names, for "Laptops, Phones +3" —
            // the picker's indent means nothing outside the tree.
            'categoryNames' => array_map(
                fn (string $label) => preg_replace('/^(?:—\s*)+/u', '', $label),
                app(CategoryTree::class)->options(),
            ),
        ]);
    }

    /** The list's in-place switch: answers with the cell. */
    public function setStatus(Request $request, FilterRule $rule)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $rule->update(['enabled' => $request->boolean('enabled')]);

        return view('torgash.catalog::admin.filter-rules._status', ['rule' => $rule]);
    }

    /** Several at once — deleting keeps every page the rules made, as one delete does. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $rules = FilterRule::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $rules->each->delete();

            return back()->with('status', trans_choice(
                ':count rule deleted. The pages it made are still published.|:count rules deleted. The pages they made are still published.',
                $rules->count(), ['count' => $rules->count()],
            ));
        }

        $rules->each(fn (FilterRule $rule) => $rule->update(['enabled' => $validated['action'] === 'enable']));

        return back()->with('status', trans_choice(':count rule updated.|:count rules updated.', $rules->count(), ['count' => $rules->count()]));
    }

    public function create()
    {
        return $this->form(new FilterRule(['enabled' => true, 'in_sitemap' => true, 'in_list' => true, 'with_children' => true, 'min_products' => 1]));
    }

    public function edit(FilterRule $rule)
    {
        return $this->form($rule);
    }

    public function store(Request $request)
    {
        $rule = FilterRule::create($this->data($request));

        return $this->afterSave($request, $rule, __('Rule created. Press "Generate pages" when you are happy with it.'));
    }

    public function update(Request $request, FilterRule $rule)
    {
        $rule->update($this->data($request, $rule));

        return $this->afterSave($request, $rule, __('Rule updated.'));
    }

    public function destroy(FilterRule $rule)
    {
        $rule->delete();

        return redirect()->route('admin.catalog.filter-rules.index')
            ->with('status', __('Rule deleted. The pages it made are still published.'));
    }

    /**
     * Run it. A background task, because one rule over a real catalogue is a
     * product count per value per category — and `startIfIdle()`, so pressing
     * the button twice does not queue the same work twice.
     */
    public function generate(Request $request, ?FilterRule $rule = null)
    {
        $task = GenerateFilterPages::startIfIdle($rule?->id);

        return back()->with('status', $task
            ? __('Generating filter pages in the background.')
            : __('A run is already going; this one was not queued on top of it.'));
    }

    /** Back to the form, as every edit form here does; "Save and close" is the way out. */
    protected function afterSave(Request $request, FilterRule $rule, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.catalog.filter-rules.index')
            : redirect()->route('admin.catalog.filter-rules.edit', $rule))->with('status', $message);
    }

    protected function form(FilterRule $rule)
    {
        $placeIds = array_map('intval', (array) old('place_ids', $rule->placeIds()));
        $names = $placeIds === []
            ? []
            : (array) hook_filter('locations.place.names', [], ['ids' => $placeIds]);

        return view('torgash.catalog::admin.filter-rules.form', [
            'rule' => $rule,
            // Every characteristic, not only the ones the sidebar offers: a
            // rule writes ADDRESSES, and being drawn as a control is a
            // different question (Features\Places).
            'features' => Feature::orderBy('sort')->get(),
            'categories' => app(CategoryTree::class)->options(),
            // Id AND label together: the picker has to redraw what is already
            // chosen without asking the server again, and an id alone would
            // draw a row of numbers.
            'places' => array_values(array_map(
                fn (int $id) => ['id' => $id, 'label' => $names[$id] ?? __('town #:id', ['id' => $id])],
                $placeIds,
            )),
            // The whole axis is hidden when no place registry is installed:
            // there would be nothing to search and no town to name.
            'placesAvailable' => Route::has('places.suggest'),
        ]);
    }

    /** @return array<string, mixed> */
    protected function data(Request $request, ?FilterRule $rule = null): array
    {
        $locale = admin_content_locale();

        $validated = $request->validate([
            'name' => 'required|string|max:190',
            'axis' => 'nullable|in:feature,place,price',
            'feature_id' => 'nullable|exists:m_catalog_features,id',
            'category_ids' => 'array',
            'category_ids.*' => 'integer',
            // No `exists:` rule: the places table belongs to another module,
            // and this one has to keep working with it uninstalled.
            'place_ids' => 'array',
            'place_ids.*' => 'integer|min:1',
            'price_bounds_text' => 'nullable|string',
            'slug_template' => 'nullable|string|max:190',
            'page_name' => 'nullable|string|max:255',
            'min_products' => 'nullable|integer|min:0',
            'position' => 'nullable|integer',
        ]);

        // One axis, and the form says which: a rule that carried both would
        // publish every town times every make of laptop. Which field is kept
        // is decided HERE rather than by clearing the others in the browser —
        // a disabled field is still a field somebody can re-enable, and the
        // row would then claim two axes at once.
        $axis = in_array($validated['axis'] ?? null, [FilterRule::AXIS_PLACE, FilterRule::AXIS_PRICE], true)
            ? $validated['axis']
            : FilterRule::AXIS_FEATURE;

        $featureId = $axis === FilterRule::AXIS_FEATURE ? (int) ($validated['feature_id'] ?? 0) : 0;
        $placeIds = $axis === FilterRule::AXIS_PLACE
            ? array_values(array_unique(array_filter(array_map('intval', $validated['place_ids'] ?? []))))
            : [];
        $priceBounds = $axis === FilterRule::AXIS_PRICE
            ? $this->parsePriceBounds((string) ($validated['price_bounds_text'] ?? ''))
            : [];

        if ($axis === FilterRule::AXIS_FEATURE && ! $featureId) {
            throw ValidationException::withMessages([
                'feature_id' => __('Choose the characteristic this rule makes a page per value of.'),
            ]);
        }

        if ($axis === FilterRule::AXIS_PLACE && $placeIds === []) {
            throw ValidationException::withMessages([
                'place_ids' => __('Choose at least one town. A rule with none would make nothing — and "every town" is a hundred thousand pages, so it is not the default.'),
            ]);
        }

        if ($axis === FilterRule::AXIS_PRICE && $priceBounds === []) {
            throw ValidationException::withMessages([
                'price_bounds_text' => __('Write at least one boundary, one per line, as "min-max" — either side may be empty.'),
            ]);
        }

        $names = (array) ($rule?->page_name ?? []);
        $typed = trim((string) ($validated['page_name'] ?? ''));

        if ($typed === '') {
            unset($names[$locale]);
        } else {
            $names[$locale] = $typed;
        }

        return [
            'name' => $validated['name'],
            'feature_id' => $featureId ?: null,
            'place_ids' => $placeIds ?: null,
            'price_bounds' => $priceBounds ?: null,
            'category_ids' => array_values(array_filter(array_map('intval', $validated['category_ids'] ?? []))) ?: null,
            'with_children' => $request->boolean('with_children'),
            'slug_template' => trim((string) ($validated['slug_template'] ?? ''))
                ?: match ($axis) {
                    FilterRule::AXIS_PLACE => '{location:1}',
                    FilterRule::AXIS_PRICE => '{price_min}-{price_max}',
                    default => '{filter}',
                },
            'page_name' => $names ?: null,
            'min_products' => max(0, (int) ($validated['min_products'] ?? 1)),
            'in_sitemap' => $request->boolean('in_sitemap'),
            'in_list' => $request->boolean('in_list'),
            'enabled' => $request->boolean('enabled'),
            'position' => (int) ($validated['position'] ?? 0),
        ];
    }

    /**
     * One boundary per line, "min-max" — either side may be empty, but not
     * both. NEVER quantiles computed from the catalogue (PLAN §30.14): a
     * boundary that moved with the prices would rewrite an address that has
     * already gone out, so the shop types the list by hand and this only
     * parses it.
     *
     * @return list<array{min: int|null, max: int|null}>
     */
    protected function parsePriceBounds(string $text): array
    {
        $bounds = [];

        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (! preg_match('/^(\d+(?:[.,]\d{1,2})?)?\s*-\s*(\d+(?:[.,]\d{1,2})?)?$/', $line, $m)) {
                throw ValidationException::withMessages([
                    'price_bounds_text' => __('Each line is a boundary written as "min-max" — this one is neither: ":line".', ['line' => $line]),
                ]);
            }

            $min = ($m[1] ?? '') !== '' ? FilterPage::minorAmount(str_replace(',', '.', $m[1])) : null;
            $max = ($m[2] ?? '') !== '' ? FilterPage::minorAmount(str_replace(',', '.', $m[2])) : null;

            if ($min === null && $max === null) {
                throw ValidationException::withMessages([
                    'price_bounds_text' => __('A boundary needs at least one side: ":line".', ['line' => $line]),
                ]);
            }

            if ($min !== null && $max !== null && $min > $max) {
                throw ValidationException::withMessages([
                    'price_bounds_text' => __('The lower bound is above the upper one: ":line".', ['line' => $line]),
                ]);
            }

            $bounds[] = ['min' => $min, 'max' => $max];
        }

        return $bounds;
    }
}
