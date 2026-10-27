<?php

namespace Torgash\Catalog\Http\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\PriceType;
use Torgash\Catalog\Models\PriceTypeMarkupRule;
use Torgash\Catalog\Support\CategoryTree;

class PriceTypeMarkupRulesController extends Controller
{
    /**
     * The rules are a card on the price type's own form now — one screen for
     * one price, not two. The address stays, for whatever still links here.
     */
    public function index(PriceType $priceType)
    {
        return redirect()->to(route('admin.catalog.price-types.edit', $priceType).'#rules');
    }

    public function create(PriceType $priceType)
    {
        return view('torgash.catalog::admin.price-types.rules.form', [
            'priceType' => $priceType,
            'rule' => new PriceTypeMarkupRule,
            'categoryOptions' => $this->categoryOptions(),
            'featureValueOptions' => $this->featureValueOptions(),
        ]);
    }

    public function store(Request $request, PriceType $priceType)
    {
        $rule = $priceType->markupRules()->create($this->data($request));
        $rule->categories()->sync($request->input('category_ids', []));
        $rule->featureValues()->sync($request->input('feature_value_ids', []));

        return $this->afterSave($request, $priceType, $rule, __('Rule created.'));
    }

    public function edit(PriceType $priceType, PriceTypeMarkupRule $rule)
    {
        $rule->load(['categories', 'featureValues']);

        // Neighbours among this price type's rules, in the order they are tried.
        $ids = $priceType->markupRules()->pluck('id')->all();
        $at = array_search($rule->id, $ids, true);

        return view('torgash.catalog::admin.price-types.rules.form', [
            'priceType' => $priceType,
            'rule' => $rule,
            'prevRule' => $at !== false && $at > 0 ? PriceTypeMarkupRule::find($ids[$at - 1]) : null,
            'nextRule' => $at !== false && isset($ids[$at + 1]) ? PriceTypeMarkupRule::find($ids[$at + 1]) : null,
            'categoryOptions' => $this->categoryOptions(),
            'featureValueOptions' => $this->featureValueOptions(),
        ]);
    }

    public function update(Request $request, PriceType $priceType, PriceTypeMarkupRule $rule)
    {
        $rule->update($this->data($request));
        $rule->categories()->sync($request->input('category_ids', []));
        $rule->featureValues()->sync($request->input('feature_value_ids', []));

        return $this->afterSave($request, $priceType, $rule, __('Rule saved.'));
    }

    /** Back to the rule; "Save and close" returns to the price type's card of rules. */
    protected function afterSave(Request $request, PriceType $priceType, PriceTypeMarkupRule $rule, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->to(route('admin.catalog.price-types.edit', $priceType).'#rules')
            : redirect()->route('admin.catalog.price-types.rules.edit', [$priceType, $rule]))->with('status', $message);
    }

    public function destroy(PriceType $priceType, PriceTypeMarkupRule $rule)
    {
        $rule->delete();

        return redirect()->to(route('admin.catalog.price-types.edit', $priceType).'#rules')->with('status', __('Rule deleted.'));
    }

    protected function data(Request $request): array
    {
        $locale = admin_content_locale();

        $validated = $request->validate([
            'name' => 'nullable|string',
            'cost_price_min' => 'nullable|numeric|min:0',
            'cost_price_max' => 'nullable|numeric|min:0',
            'markup_type' => 'required|in:percent,fixed',
            'markup_value' => 'required|numeric',
            'rounding_mode' => 'required|in:nearest,psychological',
            'round_to' => 'required|numeric|min:1',
            'round_direction' => ['nullable', Rule::in(['nearest', 'up', 'down'])],
            'psychological_offset' => 'nullable|numeric|min:0',
            'sort' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
        ]);

        return [
            'name' => trim($validated['name'] ?? '') !== '' ? [$locale => $validated['name']] : null,
            // cost_price_min/max, round_to compare/operate in minor units, same convention as Product::price.
            'cost_price_min' => isset($validated['cost_price_min']) ? (int) round($validated['cost_price_min'] * 100) : null,
            'cost_price_max' => isset($validated['cost_price_max']) ? (int) round($validated['cost_price_max'] * 100) : null,
            'markup_type' => $validated['markup_type'],
            // Percent entered as e.g. "35" -> stored as basis points (3500 = 35.00%);
            // fixed entered in major units -> minor units. Same *100 scale either way.
            'markup_value' => (int) round($validated['markup_value'] * 100),
            'rounding_mode' => $validated['rounding_mode'],
            // Entered in major units (e.g. "10" = round to nearest $10), stored in minor
            // units so it composes directly with the already-minor-unit marked-up price.
            'round_to' => (int) round($validated['round_to'] * 100),
            'round_direction' => $validated['rounding_mode'] === 'nearest' ? ($validated['round_direction'] ?? 'nearest') : null,
            'psychological_offset' => $validated['rounding_mode'] === 'psychological'
                ? (int) round(($validated['psychological_offset'] ?? 0) * 100) : null,
            'sort' => $validated['sort'] ?? 0,
            'enabled' => $request->boolean('enabled'),
        ];
    }

    protected function categoryOptions(): array
    {
        $tree = app(CategoryTree::class);

        return $tree->ordered()
            ->map(fn (Category $c) => ['id' => $c->id, 'label' => $tree->indent($c)])->all();
    }

    protected function featureValueOptions(): array
    {
        return Feature::with('values')->get()
            ->flatMap(fn (Feature $f) => $f->values->map(fn ($v) => [
                'id' => $v->id,
                'label' => $f->t('name', admin_content_locale()).': '.$v->display(admin_content_locale()),
            ]))->all();
    }
}
