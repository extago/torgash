<?php

namespace Torgash\Catalog\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Features\MergeValues;
use Torgash\Catalog\Features\Places;
use Torgash\Catalog\Features\Spelling;
use Torgash\Catalog\Filters\FilterQuery;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureGroup;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\ProductFeature;
use Torgash\Catalog\Support\CategoryTree;

class FeaturesController extends Controller
{
    /** The purposes a list can be narrowed to, in the order the tabs show them. */
    public const PURPOSES = Feature::PURPOSES;

    /**
     * The list, searchable and paged, narrowed by purpose. A GET form, so
     * every state of the screen is an address the back button returns to.
     * The search reads the name in EVERY language (the jsonb as text) and
     * the code, because either is what somebody remembers.
     */
    /** How many rows a page may show. */
    public const PER_PAGE = [50, 100, 200];

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $purpose = in_array($request->query('purpose'), self::PURPOSES, true) ? $request->query('purpose') : null;
        // How many rows a page shows — the admin's choice at the toolbar.
        $per = in_array($request->integer('per'), self::PER_PAGE, true) ? $request->integer('per') : self::PER_PAGE[0];

        $search = fn ($query) => $query->when($q !== '', fn ($w) => $w->where(fn ($or) => $or
            ->whereRaw('name::text ilike ?', ['%'.$q.'%'])
            ->orWhere('code', 'ilike', '%'.$q.'%')));

        $features = $search(Feature::query())
            ->when($purpose, fn ($w) => $w->where('purpose', $purpose))
            ->with(['group'])
            ->withCount(['values', 'assignments'])
            ->orderBy('group_id')->orderBy('sort')->orderBy('id')
            ->paginate($per)
            ->withQueryString();

        return view('torgash.catalog::admin.features.index', [
            'features' => $features,
            'places' => app(Places::class)->all(),
            'filters' => ['q' => $q, 'purpose' => $purpose, 'per' => $per],
            // Counts for the purpose tabs, within the search in force.
            'purposeCounts' => $search(Feature::query())->selectRaw('purpose, count(*) as n')->groupBy('purpose')->pluck('n', 'purpose'),
        ]);
    }

    /**
     * Several at once: become plain characteristics or variation axes, or be
     * deleted. Not "become a brand" — a brand's values are pages with their
     * own fields, which is a decision taken one characteristic at a time.
     */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['filter', 'variation', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        // Through the models, one by one: the audit and the code rules hang
        // off Eloquent events, which a query-builder update walks past.
        $features = Feature::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $features->each->delete();
            $message = trans_choice(':count characteristic deleted.|:count characteristics deleted.', $features->count(), ['count' => $features->count()]);
        } else {
            $features->each(function (Feature $feature) use ($validated) {
                $feature->purpose = $validated['action'];
                $feature->save();
            });
            $message = trans_choice(':count characteristic updated.|:count characteristics updated.', $features->count(), ['count' => $features->count()]);
        }

        return redirect()->back()->with('status', $message);
    }

    public function create()
    {
        return view('torgash.catalog::admin.features.form', [
            'feature' => new Feature,
            'groups' => FeatureGroup::orderBy('sort')->get(),
            'categories' => app(CategoryTree::class)->options(),
            'places' => app(Places::class)->all(),
            'urlName' => null,
            'urlValue' => null,
        ]);
    }

    public function store(Request $request)
    {
        DB::transaction(function () use ($request) {
            $feature = Feature::create($this->data($request, null));
            $this->syncValues($request, $feature);
            $feature->categories()->sync($request->input('categories', []));
        });

        return redirect()->route('admin.catalog.features.index')->with('status', __('Characteristic created.'));
    }

    public function edit(Feature $feature)
    {
        $feature->load(['values', 'categories']);

        $query = app(FilterQuery::class);
        $urlName = $query->paramFor($feature);

        return view('torgash.catalog::admin.features.form', [
            'feature' => $feature,
            'groups' => FeatureGroup::orderBy('sort')->get(),
            'categories' => app(CategoryTree::class)->options(),
            'places' => app(Places::class)->all(),
            // What a filtered address will call this characteristic, shown so
            // the shop can see that filling a code is what makes the address
            // readable — otherwise the whole thing is invisible until somebody
            // happens to look at a URL.
            'urlName' => $urlName,
            'urlValue' => $urlName
                ? $query->valueParamFor($feature->values->first() ?? new FeatureValue, $feature)
                : null,
            // For the merge window: how many products each value would move,
            // and which values are written alike (Features\Spelling).
            'valueProducts' => ProductFeature::where('feature_id', $feature->id)->whereNotNull('feature_value_id')
                ->selectRaw('feature_value_id, count(*) as n')->groupBy('feature_value_id')
                ->pluck('n', 'feature_value_id')->map(fn ($n) => (int) $n)->all(),
            'spellingGroups' => Spelling::groups($feature->values),
        ]);
    }

    /**
     * Several values become one (PLAN §60). Features\MergeValues does the
     * work and refuses what it must — its refusal comes back as this form's
     * error; this only reads the window and says what happened.
     */
    public function mergeValues(Request $request, Feature $feature, MergeValues $merge)
    {
        $validated = $request->validate([
            'into' => ['required', 'integer', Rule::exists('m_catalog_feature_values', 'id')->where('feature_id', $feature->id)],
            'values' => 'required|array|min:1',
            'values.*' => 'integer',
        ], [
            'into.required' => __('Choose the value to keep.'),
            'values.required' => __('Tick the values to merge into it.'),
        ]);

        $into = FeatureValue::where('feature_id', $feature->id)->findOrFail($validated['into']);
        $done = $merge->merge($into, $validated['values']);

        $details = array_filter([
            $done['products'] ? __('products moved: :count', ['count' => $done['products']]) : null,
            $done['filter_pages'] ? __('filter pages: :count', ['count' => $done['filter_pages']]) : null,
            $done['labels'] ? __('labels: :count', ['count' => $done['labels']]) : null,
            $done['markup_rules'] ? __('markup rules: :count', ['count' => $done['markup_rules']]) : null,
        ]);

        return redirect()->route('admin.catalog.features.edit', $feature)->with('status', trim(
            trans_choice(':count value merged into ":into".|:count values merged into ":into".', $done['values'], [
                'count' => $done['values'],
                'into' => $into->fresh()->display(admin_content_locale()),
            ]).($details ? ' '.Str::ucfirst(implode(', ', $details)).'.' : ''),
        ));
    }

    public function update(Request $request, Feature $feature)
    {
        DB::transaction(function () use ($request, $feature) {
            $feature->update($this->data($request, $feature));
            $this->syncValues($request, $feature);
            $feature->categories()->sync($request->input('categories', []));
        });

        return redirect()->route('admin.catalog.features.index')->with('status', __('Characteristic saved.'));
    }

    public function destroy(Feature $feature)
    {
        $feature->delete();

        return redirect()->route('admin.catalog.features.index')->with('status', __('Characteristic deleted.'));
    }

    protected function data(Request $request, ?Feature $feature): array
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            'name' => [Rule::requiredIf($isBaseLocale), 'nullable', 'string'],
            'code' => ['nullable', 'string', 'max:191', 'regex:/^[A-Z0-9_-]+$/i', Rule::unique('m_catalog_features', 'code')->ignore($feature?->id)],
            'group_name' => 'nullable|string',
            // What the characteristic IS. Where it is SHOWN is `shown_in`
            // below — one column answering both questions is what made "in the
            // filters but not among the specifications" impossible to ask for.
            'purpose' => 'required|in:filter,variation,brand',
            'input_type' => 'required|in:select,text,number',
            'shown_in' => 'nullable|array',
            'is_color' => 'nullable|boolean',
            'in_short_description' => 'nullable|boolean',
            'product_style' => ['nullable', Rule::in(Feature::PRODUCT_STYLES)],
            'filter_style' => ['nullable', Rule::in(Feature::FILTER_STYLES)],
        ]);

        $name = $feature?->name ?? [];
        if (trim($validated['name'] ?? '') === '') {
            unset($name[$locale]);
        } else {
            $name[$locale] = $validated['name'];
        }

        // An empty box is answered by the MODEL (Support\AssignsCode), not
        // here: an import, 1C and the API create characteristics too, and a
        // rule that lived in this controller left all three of them writing
        // rows that nothing could match on. Typed-and-taken is still a hard
        // validation error above — the trait would quietly rename it, which is
        // right for an import and wrong for somebody typing.
        return [
            'name' => $name,
            'code' => trim($validated['code'] ?? '') ?: null,
            'group_id' => $this->resolveGroupId($validated['group_name'] ?? null),
            'purpose' => $validated['purpose'],
            // Only keys a module actually answers for: a place whose module has
            // been uninstalled is not kept alive by a tick that outlived it,
            // and an invented one never reaches the column. An empty list is a
            // real answer — the characteristic is drawn nowhere and is still
            // what a published address filters by.
            'shown_in' => array_values(array_intersect(
                app(Places::class)->keys(),
                array_map('strval', (array) ($validated['shown_in'] ?? [])),
            )),
            // Brand values need their own page — only meaningful for a choice list.
            'input_type' => $validated['purpose'] === 'brand' ? 'select' : $validated['input_type'],
            'is_color' => $request->boolean('is_color'),
            // Which characteristics stand in for a product's short description
            // — see Torgash\Catalog\Support\ShortDescription.
            'in_short_description' => $request->boolean('in_short_description'),
            // How the values are drawn — see Feature::PRODUCT_STYLES.
            'product_style' => $validated['product_style'] ?? 'labels',
            'filter_style' => $validated['filter_style'] ?? 'list',
        ];
    }

    /**
     * Free-text "group" field matched case-insensitively against any existing
     * group's name before creating a new one — the whole point of splitting
     * global features out of free-form JSONB is to stop near-duplicate rows
     * piling up (the pain point the user hit importing/parsing product data),
     * so the same care applies to groups.
     */
    protected function resolveGroupId(?string $name): ?int
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        // Against the name in ANY language, and a new group is named in the
        // language the admin is writing in.
        $existing = FeatureGroup::whereRaw('exists (select 1 from jsonb_each_text(name) as t(k, v) where lower(t.v) = lower(?))', [$name])
            ->first();

        return $existing?->id ?? FeatureGroup::create(['name' => put_translation(null, $name)])->id;
    }

    /**
     * Full replace-sync: delete removed rows, update kept ones, create new
     * ones. Rows arrive as one JSON blob (same house style as the product
     * form's Price Tiers / Individual Attributes repeaters) rather than
     * bracket-named inputs — each row is
     * {id, value: {<locale>: text, …}, code, position, swatch}, `value`
     * mirroring the JSONB column shape directly, every language included.
     */
    protected function syncValues(Request $request, Feature $feature): void
    {
        if (! $feature->isChoiceType()) {
            $feature->values()->delete();

            return;
        }

        $rows = json_decode($request->input('values_json', '[]'), true) ?: [];
        $this->assertNoDuplicateValueCodes($rows, $feature);
        $this->assertNoTwinSpellings($rows, $feature);
        $keptIds = [];

        foreach ($rows as $i => $row) {
            $value = $this->rowNames($row);
            if ($value === []) {
                continue;
            }
            $existingId = (! empty($row['id']) && $feature->values->contains('id', $row['id'])) ? (int) $row['id'] : null;
            $attrs = [
                'value' => $value,
                // Blank means "name it yourself", answered by the model
                // (Support\AssignsCode) — including for a value whose name is
                // Cyrillic, which is the ordinary case and used to become
                // ITEM-2.
                'code' => trim((string) ($row['code'] ?? '')) ?: null,
                'sort' => is_numeric($row['position'] ?? null) ? (int) $row['position'] : $i,
                'swatch' => $feature->is_color ? ($row['swatch'] ?? null) : null,
            ];

            // A spelling merged into the value can be let go (the × beside
            // it): the next import then makes it a value of its own again.
            // Only ever FEWER — the list is what a merge wrote, not a field.
            if ($existingId && array_key_exists('aliases', $row)) {
                $kept = array_values(array_filter(
                    (array) ($feature->values->firstWhere('id', $existingId)?->aliases ?? []),
                    fn ($alias) => in_array($alias, (array) $row['aliases']),
                ));
                $attrs['aliases'] = $kept ?: null;
            }

            // Saved through the MODEL rather than a query-builder update: a
            // builder update fires no Eloquent events at all, so the row would
            // walk straight past the one place a code is assigned (and past
            // the audit trail with it).
            if ($existingId) {
                $feature->values()->find($existingId)?->fill($attrs)->save();
                $keptIds[] = $existingId;
            } else {
                $keptIds[] = $feature->values()->create($attrs)->id;
            }
        }

        $feature->values()->whereNotIn('id', $keptIds ?: [0])->delete();
    }

    /**
     * Every language the row carries — the screen edits one and hands the
     * others back untouched, so a third language survives a save.
     *
     * @return array<string, string>
     */
    protected function rowNames(array $row): array
    {
        return collect((array) ($row['value'] ?? []))
            ->filter(fn ($text, $locale) => is_string($locale) && preg_match('/^[a-z]{2,5}$/', $locale))
            ->map(fn ($text) => trim((string) $text))
            ->filter(fn ($text) => $text !== '')
            ->all();
    }

    /**
     * A value added or renamed so that it is written like another one —
     * "Темно-синий" beside "тёмно‑синий" (Features\Spelling) — is refused:
     * that is how a shop ends up with two values nobody can tell apart, each
     * with half the products. Rows that were ALREADY alike pass, or a shop with
     * old twins could not save the page at all; the page offers to merge them.
     */
    protected function assertNoTwinSpellings(array $rows, Feature $feature): void
    {
        $owners = [];

        foreach ($rows as $i => $row) {
            $names = $this->rowNames($row);
            $stored = ! empty($row['id']) ? $feature->values->firstWhere('id', (int) $row['id']) : null;
            $changed = ! $stored || $names != $this->rowNames(['value' => $stored->value]);

            foreach ($names as $text) {
                $key = Spelling::key($text);
                $owner = $owners[$key] ?? null;

                if ($owner && $owner['row'] !== $i && ($changed || $owner['changed'])) {
                    throw ValidationException::withMessages(['values' => __('":value" is written like ":other" — they differ only in letter case, dashes or spaces. Make them one with "Merge values".', [
                        'value' => $text,
                        'other' => $owner['text'],
                    ])]);
                }

                $owners[$key] ??= ['row' => $i, 'text' => $text, 'changed' => $changed];
            }
        }
    }

    /**
     * Only checks codes the admin actually typed — auto-suggested ones
     * de-duplicate themselves in Support\AssignsCode and can't collide.
     */
    protected function assertNoDuplicateValueCodes(array $rows, Feature $feature): void
    {
        $seen = [];

        foreach ($rows as $i => $row) {
            $code = trim((string) ($row['code'] ?? ''));
            if ($code === '') {
                continue;
            }

            $upper = mb_strtoupper($code);
            if (isset($seen[$upper])) {
                throw ValidationException::withMessages(['values' => "Value code \"{$code}\" is used more than once."]);
            }
            $seen[$upper] = true;

            $existingId = (! empty($row['id']) && $feature->values->contains('id', $row['id'])) ? (int) $row['id'] : null;
            $taken = FeatureValue::where('feature_id', $feature->id)->where('code', $code)
                ->when($existingId, fn ($q) => $q->where('id', '!=', $existingId))
                ->exists();

            if ($taken) {
                throw ValidationException::withMessages(['values' => "Value code \"{$code}\" is already used by another value on this feature."]);
            }
        }
    }
}
