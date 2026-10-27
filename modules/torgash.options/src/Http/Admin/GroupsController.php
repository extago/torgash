<?php

namespace Torgash\Options\Http\Admin;

use App\Core\Display\DisplayRules;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Pricing\Prices;
use Torgash\Catalog\Selections\Sources;
use Torgash\Options\Groups;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;
use Torgash\Options\PriceRules;

/**
 * The questions and their answers (PLAN §33.4).
 *
 * One screen for both kinds of group, because they are one table and the
 * difference is one column: a group with a product belongs to it and is
 * reached from its form, a group with none is placed by display rules.
 * Creating from a product's own tab carries `?product=` through, which is the
 * only difference between the two paths — the same shape the product tabs
 * screen already has.
 *
 * Answers are edited WITH the question: a choice on its own is meaningless,
 * and a second screen for them would mean saving a question that has nothing
 * to answer it.
 */
class GroupsController extends Controller
{
    /**
     * The list, searched by either name in any language, narrowed by whose
     * the group is (shared / one product's) and by status, paged — a shop with
     * a configurator writes a group per product, so this runs to hundreds.
     */
    public function index(Request $request)
    {
        $filters = $this->filtersFrom($request);

        $counts = OptionGroup::query()
            ->selectRaw('count(*) as all_n')
            ->selectRaw('count(*) filter (where product_id is null) as shared_n')
            ->selectRaw('count(*) filter (where product_id is not null) as product_n')
            ->selectRaw('count(*) filter (where enabled) as enabled_n')
            ->selectRaw('count(*) filter (where not enabled) as disabled_n')
            ->first();

        return view('torgash.options::admin.groups.index', [
            'groups' => $this->filtered($filters)
                ->with('product')->withCount('choices')
                ->orderByRaw('product_id nulls first')
                ->orderBy('sort')->orderBy('id')
                ->paginate(50)->withQueryString(),
            'filters' => $filters,
            'counts' => $counts,
        ]);
    }

    /** @return array{q: string, whose: ?string, status: ?string} */
    protected function filtersFrom(Request $request): array
    {
        return [
            'q' => trim((string) $request->query('q', '')),
            'whose' => in_array($request->query('whose'), ['shared', 'product'], true) ? $request->query('whose') : null,
            'status' => in_array($request->query('status'), ['enabled', 'disabled'], true) ? $request->query('status') : null,
        ];
    }

    protected function filtered(array $filters): \Illuminate\Database\Eloquent\Builder
    {
        // What the admin typed, as a substring: % and _ are theirs, not wildcards.
        $like = '%'.addcslashes($filters['q'], '%_\\').'%';

        return OptionGroup::query()
            ->when($filters['q'] !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('admin_name', 'ilike', $like)
                ->orWhereRaw('name::text ilike ?', [$like])
                ->orWhereHas('product', fn ($p) => $p->where('sku', 'ilike', $like)->orWhereRaw('name::text ilike ?', [$like]))))
            ->when($filters['whose'] === 'shared', fn ($q) => $q->whereNull('product_id'))
            ->when($filters['whose'] === 'product', fn ($q) => $q->whereNotNull('product_id'))
            ->when($filters['status'], fn ($q, $s) => $q->where('enabled', $s === 'enabled'));
    }

    /** The list's in-place switch: answers with the status cell. */
    public function setStatus(Request $request, OptionGroup $group)
    {
        $request->validate(['enabled' => 'required|boolean']);
        $group->update(['enabled' => $request->boolean('enabled')]);
        app(Groups::class)->flush();

        return view('torgash.options::admin.groups._status', ['group' => $group]);
    }

    /** Several at once — through the models, so the audit hears each one. */
    public function bulk(Request $request)
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['enable', 'disable', 'delete'])],
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $groups = OptionGroup::whereIn('id', $validated['ids'])->get();

        if ($validated['action'] === 'delete') {
            $groups->each->delete();
            $message = trans_choice(':count option deleted.|:count options deleted.', $groups->count(), ['count' => $groups->count()]);
        } else {
            $groups->each(fn (OptionGroup $g) => $g->update(['enabled' => $validated['action'] === 'enable']));
            $message = trans_choice(':count option updated.|:count options updated.', $groups->count(), ['count' => $groups->count()]);
        }

        app(Groups::class)->flush();

        return redirect()->back()->with('status', $message);
    }

    public function create(Request $request)
    {
        $productId = (int) $request->query('product', 0);

        return $this->form(new OptionGroup([
            'type' => 'select',
            'enabled' => true,
            'per_unit' => true,
            'min_qty' => 1,
            'sort' => 0,
            'rules_match' => DisplayRules::MATCH_ALL,
            'product_id' => Product::whereKey($productId)->value('id'),
        ]));
    }

    public function store(Request $request)
    {
        $group = OptionGroup::create($this->data($request, null));
        $this->saveChoices($request, $group);
        app(Groups::class)->flush();

        return $this->afterSave($request, $group, __('Option group created.'));
    }

    public function edit(OptionGroup $group)
    {
        $group->load('choices');

        return $this->form($group);
    }

    public function update(Request $request, OptionGroup $group)
    {
        $group->update($this->data($request, $group));
        $this->saveChoices($request, $group);
        app(Groups::class)->flush();

        return $this->afterSave($request, $group, __('Option group saved.'));
    }

    public function destroy(OptionGroup $group)
    {
        $product = $group->product;
        $group->delete();
        app(Groups::class)->flush();

        // A cart line holding an answer to a deleted group prices that answer
        // at nothing and drops it — `Configurator::parts()` skips a choice it
        // cannot find, which is the honest reading of "the shop no longer sells
        // that": the product is still buyable, the extra is not.
        //
        // Deleted from the list: stay on the list (its page and filters).
        // Deleted from its own form: that page is gone — back to where the
        // group was reached from.
        $previous = url()->previous();
        if (str_contains($previous, '/admin/options/groups') && ! str_contains($previous, '/edit')) {
            return redirect()->to($previous)->with('status', __('Option group deleted.'));
        }

        return $product
            ? redirect()->route('admin.catalog.products.edit', $product)->with('status', __('Option group deleted.'))
            : redirect()->route('admin.options.groups.index')->with('status', __('Option group deleted.'));
    }

    /**
     * "This shared group does not apply to this product." A row rather than a
     * column on the product: it is this module's opinion about another
     * module's data, and uninstalling has to take it with it.
     */
    public function toggleExclusion(Request $request, OptionGroup $group)
    {
        $product = Product::findOrFail((int) $request->input('product_id'));

        abort_unless($group->isShared(), 404);

        $excluded = DB::table('m_options_group_exclusions')
            ->where('group_id', $group->id)->where('product_id', $product->id)->exists();

        if ($excluded) {
            DB::table('m_options_group_exclusions')
                ->where('group_id', $group->id)->where('product_id', $product->id)->delete();
        } else {
            DB::table('m_options_group_exclusions')->insert([
                'group_id' => $group->id,
                'product_id' => $product->id,
            ]);
        }

        app(Groups::class)->flush();

        return back()->with('status', $excluded
            ? __('The group applies to this product again.')
            : __('The group no longer applies to this product.'));
    }

    /**
     * A shared group copied into a product's own — the answer to "this product
     * needs a different price for the same question" (PLAN §33.8). Copying is
     * the deliberate alternative to a per-product override: what is on screen
     * afterwards says what it costs, with nothing hidden behind it.
     */
    public function copyToProduct(Request $request, OptionGroup $group)
    {
        $product = Product::findOrFail((int) $request->input('product_id'));

        abort_unless($group->isShared(), 404);

        $copy = $group->replicate(['created_at', 'updated_at']);
        $copy->product_id = $product->id;
        $copy->rules = null;
        $copy->rules_match = DisplayRules::MATCH_ALL;
        $copy->save();

        foreach ($group->choices as $choice) {
            $choiceCopy = $choice->replicate(['created_at', 'updated_at']);
            $choiceCopy->group_id = $copy->id;
            $choiceCopy->save();
        }

        // Its own copy replaces the shared one for this product, or the
        // customer would be asked the same question twice.
        DB::table('m_options_group_exclusions')->insertOrIgnore([
            'group_id' => $group->id,
            'product_id' => $product->id,
        ]);

        app(Groups::class)->flush();

        return redirect()->route('admin.options.groups.edit', $copy)
            ->with('status', __('Copied. This product now has its own copy of the option.'));
    }

    protected function form(OptionGroup $group)
    {
        return view('torgash.options::admin.groups.form', [
            'group' => $group,
            'rules' => app(PriceRules::class),
            'ruleOptions' => app(PriceRules::class)->options(),
            'ruleFields' => collect(app(PriceRules::class)->all())
                ->map(fn (array $rule) => array_values((array) ($rule['fields'] ?? [])))->all(),
            // Every product source EXCEPT the one whose argument lives in a
            // block's own settings: "picked by hand" has nothing to pick from
            // here, and offering it would offer a set that is always empty.
            'sourceOptions' => collect(app(Sources::class)->options())
                ->reject(fn (array $option) => str_starts_with($option['value'], 'manual'))
                ->values()->all(),
            'currencies' => app(\App\Core\Support\Currencies::class)->all(),
            'dependencyOptions' => $this->dependencyOptions($group),
        ]);
    }

    /**
     * The questions this one could be made to wait for.
     *
     * A product's own question may wait for another of its own or for a shared
     * one (which may or may not apply here — `Groups::asked()` hides it if it
     * does not, fail-closed like everything else). A SHARED question may only
     * wait for another shared one: it is asked on thousands of products, and
     * one particular product's question means nothing to it.
     *
     * Their answers come with them, because "only these answers count" is a
     * list of tick boxes and the form would otherwise need a second request to
     * fill it.
     *
     * @return list<array{id: int, label: string, choices: array<int, string>}>
     */
    protected function dependencyOptions(OptionGroup $group): array
    {
        $query = OptionGroup::with('choices')->where('enabled', true);

        $group->product_id
            ? $query->where(fn ($q) => $q->where('product_id', $group->product_id)->orWhereNull('product_id'))
            : $query->whereNull('product_id');

        if ($group->exists) {
            $query->whereKeyNot($group->id);
        }

        return $query->orderBy('sort')->orderBy('id')->get()
            // A question the customer WRITES has no answers to hang anything
            // off: "engraved" is not a choice, it is a piece of text, and
            // "asked only once something was typed" is a rule nobody wants.
            ->reject(fn (OptionGroup $other) => $other->isTyped())
            ->map(fn (OptionGroup $other) => [
                'id' => (int) $other->id,
                'label' => $other->admin_name.($other->isShared() ? ' ('.__('shared').')' : ''),
                'choices' => $other->choices
                    ->filter(fn (OptionChoice $choice) => $choice->enabled)
                    ->mapWithKeys(fn (OptionChoice $choice) => [(int) $choice->id => $choice->label()])
                    ->all(),
            ])
            ->values()->all();
    }

    /**
     * The question this one waits for — refused when it is itself, or when it
     * would close a circle.
     *
     * A circle is a corrupt pair rather than an impossible one, and
     * `Groups::asked()` already survives it by hiding both. That is the right
     * answer at read time and the wrong one here: two questions that quietly
     * vanish from every product are far harder to explain than a form that
     * says no.
     */
    protected function dependency(?int $onId, ?OptionGroup $group): ?int
    {
        if (! $onId || ! $group?->exists) {
            return $onId ?: null;
        }

        $seen = [];
        $cursor = $onId;

        while ($cursor > 0 && ! isset($seen[$cursor])) {
            if ($cursor === (int) $group->id) {
                throw ValidationException::withMessages([
                    'depends_on_group_id' => __('That would make a circle: the two options would wait for each other and neither would ever be asked.'),
                ]);
            }

            $seen[$cursor] = true;
            $cursor = (int) OptionGroup::whereKey($cursor)->value('depends_on_group_id');
        }

        return $onId;
    }

    /** Back to the form; "Save and close" returns to wherever the group is listed. */
    protected function afterSave(Request $request, OptionGroup $group, string $status)
    {
        if ($request->input('after_save') === 'close') {
            return ($group->product
                ? redirect()->route('admin.catalog.products.edit', $group->product)
                : redirect()->route('admin.options.groups.index'))->with('status', $status);
        }

        return redirect()->route('admin.options.groups.edit', $group)->with('status', $status);
    }

    /** @return array<string, mixed> */
    protected function data(Request $request, ?OptionGroup $group): array
    {
        $locale = admin_content_locale();
        $isBaseLocale = $locale === content_base_locale();

        $validated = $request->validate([
            'name' => [Rule::requiredIf($isBaseLocale), 'nullable', 'string', 'max:190'],
            'hint' => ['nullable', 'string', 'max:500'],
            'admin_name' => ['required', 'string', 'max:190'],
            'type' => ['required', Rule::in(OptionGroup::TYPES)],
            'product_id' => ['nullable', 'integer', 'exists:m_catalog_products,id'],
            'min_select' => ['nullable', 'integer', 'min:0', 'max:99'],
            'max_select' => ['nullable', 'integer', 'min:1', 'max:99'],
            'min_qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'max_qty' => ['nullable', 'integer', 'min:1', 'max:999'],
            'max_length' => ['nullable', 'integer', 'min:1', 'max:2000'],
            'max_kb' => ['nullable', 'integer', 'min:1', 'max:1048576'],
            'file_types' => ['nullable', 'string', 'max:190'],
            'qty_steps' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'integer'],
            'depends_on_group_id' => ['nullable', 'integer', 'exists:m_options_groups,id'],
            'depends_on_choices' => ['nullable', 'array'],
            'depends_on_choices.*' => ['integer'],
        ]);

        $dependsOn = $this->dependency($validated['depends_on_group_id'] ?? null, $group);

        return [
            'name' => $this->translatable($group?->name, $validated['name'] ?? '', $locale),
            'hint' => $this->translatable($group?->hint, $validated['hint'] ?? '', $locale) ?: null,
            'admin_name' => $validated['admin_name'],
            'type' => $validated['type'],
            'product_id' => $validated['product_id'] ?? null,
            'required' => $request->boolean('required'),
            'per_unit' => $request->boolean('per_unit'),
            'min_select' => $validated['min_select'] ?? null,
            'max_select' => $validated['max_select'] ?? null,
            'min_qty' => max(1, (int) ($validated['min_qty'] ?? 1)),
            'max_qty' => $validated['max_qty'] ?? null,
            'max_length' => $validated['max_length'] ?? null,
            'max_kb' => $validated['max_kb'] ?? null,
            // Stored as the shop typed it; what it MEANS is worked out by
            // Files::types(), which is also what refuses a format this engine
            // will not take whatever is in the box.
            'file_types' => trim((string) ($validated['file_types'] ?? '')) ?: null,
            // "1x 2x" on the owner's own mock-up: a short list of numbers, not
            // a second way of saying what min and max already say.
            'qty_steps' => $this->steps($validated['qty_steps'] ?? null),
            'sort' => (int) ($validated['sort'] ?? 0),
            'enabled' => $request->boolean('enabled'),
            // A group belonging to a product goes where the product goes; only
            // a shared one is placed by rules.
            'rules' => ($validated['product_id'] ?? null)
                ? null
                : app(DisplayRules::class)->normalise($request->input('rules', [])),
            'rules_match' => $request->input('rules_match') === DisplayRules::MATCH_ANY
                ? DisplayRules::MATCH_ANY
                : DisplayRules::MATCH_ALL,
            'depends_on_group_id' => $dependsOn,
            // The ticked answers belong to the question being waited for, so
            // they go when it does — an orphaned list would narrow the next
            // dependency to ids from a different question, which is a filter
            // nothing matches.
            'depends_on_choices' => $dependsOn
                ? (array_values(array_map('intval', (array) ($validated['depends_on_choices'] ?? []))) ?: null)
                : null,
        ];
    }

    /**
     * The answers, rewritten from what the form posted.
     *
     * Rows are matched by id so an edit keeps the choice a cart line has
     * already answered; a row with no id is new, and one that is gone from the
     * form is deleted. Money goes through `Prices`, which is the only place in
     * the engine that turns a typed amount and a currency into the books'
     * column — so an option priced in euro follows a rate change exactly as a
     * product's own price does.
     */
    protected function saveChoices(Request $request, OptionGroup $group): void
    {
        $locale = admin_content_locale();
        $prices = app(Prices::class);
        $kept = [];
        $sort = 0;

        foreach ((array) $request->input('choices', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $kind = in_array($row['kind'] ?? '', OptionChoice::KINDS, true) ? $row['kind'] : 'plain';
            $choice = isset($row['id']) && (int) $row['id'] > 0
                ? $group->choices()->whereKey((int) $row['id'])->first() ?? new OptionChoice(['group_id' => $group->id])
                : new OptionChoice(['group_id' => $group->id]);

            $product = $kind === 'product' ? $this->productFor((string) ($row['product'] ?? '')) : null;

            // A product choice with no product and a plain one with no name are
            // both an unfinished row rather than an answer: dropped, because
            // the admin may be mid-edit and refusing the whole form would cost
            // them the rest of it.
            $name = trim((string) ($row['name'] ?? ''));

            if ($kind === 'plain' && $name === '') {
                continue;
            }

            if ($kind === 'product' && ! $product) {
                continue;
            }

            if ($kind === 'source' && trim((string) ($row['source'] ?? '')) === '') {
                continue;
            }

            $money = $prices->row($row['amount'] ?? null, $row['currency'] ?? null);

            $choice->fill([
                'group_id' => $group->id,
                'kind' => $kind,
                'name' => $kind === 'plain' ? $this->translatable($choice->name, $name, $locale) : $choice->name,
                'product_id' => $product?->id,
                'source' => $kind === 'source' ? (string) $row['source'] : null,
                'price_rule' => app(PriceRules::class)->find((string) ($row['price_rule'] ?? '')) ? $row['price_rule'] : 'fixed',
                'amount' => $money['price'],
                'amount_original' => $money['price_original'],
                'currency' => $money['price_original'] !== null ? strtoupper((string) $row['currency']) : null,
                'percent' => ($row['percent'] ?? '') === '' ? null : (float) str_replace(',', '.', (string) $row['percent']),
                'weight' => ($row['weight'] ?? '') === '' ? null : (int) $row['weight'],
                'max_qty' => ($row['max_qty'] ?? '') === '' ? null : (int) $row['max_qty'],
                'is_default' => ! empty($row['is_default']),
                'enabled' => ! isset($row['enabled']) || (bool) $row['enabled'],
                'sort' => $sort += 10,
            ])->save();

            $kept[] = $choice->id;
        }

        $group->choices()->whereNotIn('id', $kept ?: [0])->delete();
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

    /** @return list<int>|null */
    protected function steps(?string $value): ?array
    {
        $steps = collect(preg_split('/[\s,]+/', (string) $value))
            ->map(fn ($step) => (int) $step)
            ->filter(fn (int $step) => $step > 0)
            ->unique()->sort()->values()->all();

        return $steps === [] ? null : $steps;
    }

    /** Writes into $existing[$locale], removing the key when blank — the shop's own idiom. */
    protected function translatable(?array $existing, string $incoming, string $locale): array
    {
        // Core's one rule: blank removes this language, the others stay.
        return put_translation($existing, $incoming, $locale);
    }
}
