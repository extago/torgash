<?php

namespace Torgash\Points\Http\Admin;

use App\Core\Support\PriceScale;
use App\Http\Controllers\Controller;
use App\Models\CustomerGroup;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule as Check;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Feature;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Points\Models\Rule;
use Torgash\Points\Rules;

/**
 * The rules of the points programme (PLAN §74): an ordered list of "which
 * goods → what they earn, whether points pay for them", and the customer
 * groups' multipliers beside it. The order is the law, so the list is
 * dragged, a new rule is put above the first one that would leave it nothing,
 * and a rule nothing can reach says so.
 */
class RulesController extends Controller
{
    public function index()
    {
        $rules = Rule::orderBy('position')->orderBy('id')->get();
        $locale = admin_content_locale();
        $all = fn (string $key) => $rules->flatMap(fn (Rule $rule) => (array) ($rule->conditions[$key] ?? []))->unique()->all();
        $groups = CustomerGroup::orderBy('id')->get();

        return view('torgash.points::admin.rules.index', [
            'rules' => $rules,
            'dead' => Rules::dead($rules),
            'names' => [
                'categories' => app(CategoryTree::class)->names($all('categories')),
                'products' => Product::whereIn('id', $all('products'))->get()->mapWithKeys(fn (Product $p) => [$p->id => $p->sku ?: $p->t('name', $locale)])->all(),
                'feature_values' => FeatureValue::whereIn('id', $all('feature_values'))->get()->mapWithKeys(fn (FeatureValue $v) => [$v->id => $v->display($locale)])->all(),
                'groups' => $groups->mapWithKeys(fn (CustomerGroup $g) => [$g->id => $g->t('name', $locale)])->all(),
            ],
            'groups' => $groups,
            'groupMultipliers' => DB::table('m_points_groups')->pluck('multiplier', 'group_id')->all(),
        ]);
    }

    public function create()
    {
        return $this->form(new Rule(['enabled' => true, 'earn_mode' => 'default', 'spend_mode' => 'default', 'conditions' => []]));
    }

    public function edit(Rule $rule)
    {
        return $this->form($rule);
    }

    public function store(Request $request)
    {
        $data = $this->data($request);
        $rule = Rule::create($data + ['position' => Rules::placeFor($data['conditions'])]);

        return redirect()->route('admin.points.rules.edit', $rule)->with('status', __('Rule created.'));
    }

    public function update(Request $request, Rule $rule)
    {
        $rule->update($this->data($request));

        return redirect()->route('admin.points.rules.edit', $rule)->with('status', __('Rule saved.'));
    }

    public function destroy(Rule $rule)
    {
        $rule->delete();

        return redirect()->route('admin.points.rules.index')->with('status', __('Deleted.'));
    }

    /** The list as dragged. */
    public function sort(Request $request)
    {
        foreach (array_values((array) $request->input('ids', [])) as $position => $id) {
            Rule::whereKey((int) $id)->update(['position' => $position]);
        }

        return response()->json(['ok' => true]);
    }

    /** A rule nothing reaches, put just above the one in its way. */
    public function raise(Rule $rule)
    {
        $blocker = Rules::dead(Rule::orderBy('position')->orderBy('id')->get())[$rule->id] ?? null;

        if ($blocker) {
            $ids = Rule::orderBy('position')->orderBy('id')->pluck('id')->reject(fn ($id) => $id === $rule->id)->values()->all();
            array_splice($ids, (int) array_search($blocker->id, $ids, true), 0, [$rule->id]);

            foreach ($ids as $position => $id) {
                Rule::whereKey($id)->update(['position' => $position]);
            }
        }

        return back()->with('status', __('Saved.'));
    }

    public function storeGroup(Request $request)
    {
        $data = $request->validate([
            'group_id' => 'required|integer|exists:customer_groups,id',
            'multiplier' => 'required|numeric|min:0|max:100',
        ]);

        DB::table('m_points_groups')->updateOrInsert(
            ['group_id' => (int) $data['group_id']],
            ['multiplier' => (float) $data['multiplier'], 'updated_at' => now(), 'created_at' => now()],
        );

        return back()->with('status', __('Saved.'));
    }

    public function destroyGroup(int $group)
    {
        DB::table('m_points_groups')->where('group_id', $group)->delete();

        return back()->with('status', __('Deleted.'));
    }

    protected function form(Rule $rule)
    {
        $locale = admin_content_locale();
        $tree = app(CategoryTree::class);
        $conditions = Rules::clean((array) $rule->conditions);
        $productIds = array_map('intval', (array) old('product_ids', $conditions['products'] ?? []));

        return view('torgash.points::admin.rules.form', [
            'rule' => $rule,
            'conditions' => $conditions,
            'categoryOptions' => $tree->ordered()->map(fn (Category $c) => ['id' => $c->id, 'label' => $tree->indent($c)])->all(),
            'featureValueOptions' => Feature::with('values')->get()->flatMap(fn (Feature $f) => $f->values->map(fn ($v) => [
                'id' => $v->id,
                'label' => $f->t('name', $locale).': '.$v->display($locale),
            ]))->all(),
            // Only the chosen ones: the rest are asked for as the admin types.
            'productOptions' => Product::whereIn('id', $productIds)->get()
                ->map(fn (Product $p) => ['id' => $p->id, 'label' => trim(($p->sku ? $p->sku.' — ' : '').$p->t('name', $locale))])->all(),
            'groups' => CustomerGroup::orderBy('id')->get(),
        ]);
    }

    /** @return array<string, mixed> */
    protected function data(Request $request): array
    {
        $data = $request->validate([
            'name' => 'nullable|string|max:190',
            'enabled' => 'nullable|boolean',
            'category_ids' => 'nullable|array',
            'product_ids' => 'nullable|array',
            'feature_value_ids' => 'nullable|array',
            'group_ids' => 'nullable|array',
            'price_min' => ['nullable', 'numeric', 'min:0', PriceScale::amountRule()],
            'price_max' => ['nullable', 'numeric', 'min:0', PriceScale::amountRule()],
            'earn_mode' => ['nullable', Check::in(Rules::EARN_MODES)],
            'earn_value' => 'nullable|required_if:earn_mode,multiplier,percent,fixed|numeric|min:0|max:9999999',
            'spend_mode' => ['nullable', Check::in(Rules::SPEND_MODES)],
            'spend_percent' => 'nullable|required_if:spend_mode,limit|integer|min:0|max:100',
        ]);

        $earn = ($data['earn_mode'] ?? null) ?: 'default';
        $spend = ($data['spend_mode'] ?? null) ?: 'default';

        if ($earn === 'default' && $spend === 'default') {
            abort(back()->withErrors(['earn_mode' => __('A rule has to say something: what the goods earn, or whether points pay for them.')])->withInput());
        }

        $min = PriceScale::minorFrom($data['price_min'] ?? null);
        $max = PriceScale::minorFrom($data['price_max'] ?? null);

        if ($min && $max && $min > $max) {
            abort(back()->withErrors(['price_max' => __('"Price up to" is below "price from".')])->withInput());
        }

        return [
            'name' => trim((string) ($data['name'] ?? '')) ?: null,
            'enabled' => $request->boolean('enabled'),
            'conditions' => Rules::clean([
                'categories' => $data['category_ids'] ?? [],
                'products' => $data['product_ids'] ?? [],
                'feature_values' => $data['feature_value_ids'] ?? [],
                'groups' => $data['group_ids'] ?? [],
                'price_min' => $min,
                'price_max' => $max,
            ]),
            'earn_mode' => $earn,
            'earn_value' => in_array($earn, ['default', 'none'], true) ? null : (float) $data['earn_value'],
            'spend_mode' => $spend,
            'spend_percent' => $spend === 'limit' ? (int) $data['spend_percent'] : null,
        ];
    }
}
