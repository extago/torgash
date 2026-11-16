<?php

namespace Torgash\Points;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Points\Models\Rule;

/**
 * What the shop decided about a product's points (PLAN §74) — two questions,
 * answered separately: what buying it EARNS, and how much of it points may
 * PAY. Each is answered by the first of:
 *
 * 1. the product's own answer (its Bonus points tab) — always wins;
 * 2. the rules, top to bottom: the first one that matches AND says something
 *    about this question. A rule that speaks only of earning never settles
 *    paying, so "expensive goods earn half" cannot switch a ban below it off;
 * 3. the shop's settings.
 *
 * The list's order is the only law — there is no hidden "a subcategory beats
 * its category": the admin puts a narrower rule above a wider one when it is
 * created and marks a rule nothing can reach ({@see dead()}).
 *
 * A customer group's multiplier is another axis — who buys, not what — and
 * multiplies whatever was worked out.
 *
 * Everything here is a small table read once per request and matched in
 * memory against what a page has primed in batches; a basket recalculated ten
 * times asks the database nothing new.
 */
class Rules
{
    public const EARN_MODES = ['multiplier', 'percent', 'fixed', 'none'];

    public const SPEND_MODES = ['allowed', 'forbidden', 'limit'];

    /** @var list<array<string, mixed>>|null enabled rules, in order */
    protected ?array $rules = null;

    /** @var array<int, float>|null customer group → multiplier */
    protected ?array $groups = null;

    /** @var array<int, array{categories: list<int>, values: list<int>, own: ?object, outside: bool}> */
    protected array $facts = [];

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->rules ??= Rule::where('enabled', true)->orderBy('position')->orderBy('id')->get()
            ->map(fn (Rule $rule) => [
                'id' => $rule->id,
                'name' => $rule->name,
                'conditions' => self::clean((array) $rule->conditions),
                'earn_mode' => $rule->earn_mode,
                'earn_value' => (float) $rule->earn_value,
                'spend_mode' => $rule->spend_mode,
                'spend_percent' => (int) $rule->spend_percent,
            ])->all();
    }

    /** Start again: the rules or what is known of the products changed within this request. */
    public function flush(): void
    {
        $this->rules = null;
        $this->groups = null;
        $this->facts = [];
    }

    /**
     * Conditions as the engine reads them: lists of whole ids, prices in
     * minor units, nothing empty.
     *
     * @param  array<string, mixed>  $conditions
     * @return array<string, mixed>
     */
    public static function clean(array $conditions): array
    {
        $clean = [];

        foreach (['categories', 'products', 'feature_values', 'groups'] as $key) {
            $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($conditions[$key] ?? [])))));

            if ($ids !== []) {
                $clean[$key] = $ids;
            }
        }

        foreach (['price_min', 'price_max'] as $key) {
            if (is_numeric($conditions[$key] ?? null) && (int) $conditions[$key] > 0) {
                $clean[$key] = (int) $conditions[$key];
            }
        }

        return $clean;
    }

    protected function uses(string $condition): bool
    {
        foreach ($this->all() as $rule) {
            if (isset($rule['conditions'][$condition])) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the rules need to know about a page's products, in one query per
     * kind of fact — and none for a kind no rule asks about.
     *
     * @param  iterable<Product|int>  $products
     */
    public function prime(iterable $products): void
    {
        $ids = [];

        foreach ($products as $product) {
            $id = $product instanceof Product ? (int) $product->id : (int) $product;

            if ($id > 0 && ! isset($this->facts[$id])) {
                $ids[$id] = $id;
                $this->facts[$id] = ['categories' => [], 'values' => [], 'own' => null, 'outside' => false];
            }
        }

        if ($ids === []) {
            return;
        }

        $ids = array_values($ids);

        foreach (DB::table('m_points_products')->whereIn('product_id', $ids)->get() as $row) {
            $this->facts[(int) $row->product_id]['own'] = $row;
        }

        // Goods another module keeps out of the programme altogether: a gift
        // card is money, and money neither earns points nor is bought with them.
        foreach ((array) hook_filter('points.products.outside', [], ['ids' => $ids]) as $id) {
            if (isset($this->facts[(int) $id])) {
                $this->facts[(int) $id]['outside'] = true;
            }
        }

        if ($this->uses('categories')) {
            $tree = app(CategoryTree::class);

            foreach (DB::table('m_catalog_category_product')->whereIn('product_id', $ids)->get(['product_id', 'category_id']) as $row) {
                // A category is its subtree: the product is "in" every ancestor too.
                $this->facts[(int) $row->product_id]['categories'] = array_values(array_unique([
                    ...$this->facts[(int) $row->product_id]['categories'],
                    ...array_map('intval', $tree->ancestors((int) $row->category_id, includeSelf: true)),
                ]));
            }
        }

        if ($this->uses('feature_values')) {
            // A variation that takes its content from another product has that product's brand.
            $sources = DB::table('m_catalog_products')->whereIn('id', $ids)->whereNotNull('content_source_id')
                ->pluck('content_source_id', 'id')->map('intval')->all();
            $values = [];

            foreach (DB::table('m_catalog_product_features')->whereIn('product_id', [...$ids, ...array_values($sources)])
                ->whereNotNull('feature_value_id')->get(['product_id', 'feature_value_id']) as $row) {
                $values[(int) $row->product_id][] = (int) $row->feature_value_id;
            }

            foreach ($ids as $id) {
                $this->facts[$id]['values'] = array_values(array_unique([...($values[$id] ?? []), ...($values[$sources[$id] ?? 0] ?? [])]));
            }
        }
    }

    /** @return array{categories: list<int>, values: list<int>, own: ?object, outside: bool} */
    protected function facts(int $productId): array
    {
        if (! isset($this->facts[$productId])) {
            $this->prime([$productId]);
        }

        return $this->facts[$productId] ?? ['categories' => [], 'values' => [], 'own' => null, 'outside' => false];
    }

    /**
     * Whether a rule's conditions hold for one product at one price (minor
     * units for one piece) and one customer. Every condition present must
     * hold; inside a list any one is enough.
     *
     * @param  array<string, mixed>  $conditions
     */
    public function matches(array $conditions, int $productId, int $unit, ?User $user): bool
    {
        $facts = $this->facts($productId);

        return (! isset($conditions['products']) || in_array($productId, $conditions['products'], true))
            && (! isset($conditions['categories']) || array_intersect($conditions['categories'], $facts['categories']) !== [])
            && (! isset($conditions['feature_values']) || array_intersect($conditions['feature_values'], $facts['values']) !== [])
            && (! isset($conditions['groups']) || in_array((int) $user?->customer_group_id, $conditions['groups'], true))
            && (! isset($conditions['price_min']) || $unit >= $conditions['price_min'])
            && (! isset($conditions['price_max']) || $unit <= $conditions['price_max']);
    }

    /**
     * What buying one piece earns BY — not yet a number of points.
     *
     * @return array{mode: string, value: float, source: string, rule: ?array<string, mixed>}
     *                                                                                        mode: default | multiplier | percent | fixed | none;
     *                                                                                        source: product | rule | shop | outside
     */
    public function earn(int $productId, int $unit, ?User $user): array
    {
        $facts = $this->facts($productId);

        if ($facts['outside']) {
            return ['mode' => 'none', 'value' => 0.0, 'source' => 'outside', 'rule' => null];
        }

        if ($facts['own'] && in_array($facts['own']->earn_mode, self::EARN_MODES, true)) {
            return ['mode' => $facts['own']->earn_mode, 'value' => (float) $facts['own']->earn_value, 'source' => 'product', 'rule' => null];
        }

        foreach ($this->all() as $rule) {
            if (in_array($rule['earn_mode'], self::EARN_MODES, true) && $this->matches($rule['conditions'], $productId, $unit, $user)) {
                return ['mode' => $rule['earn_mode'], 'value' => $rule['earn_value'], 'source' => 'rule', 'rule' => $rule];
            }
        }

        return ['mode' => 'default', 'value' => 1.0, 'source' => 'shop', 'rule' => null];
    }

    /**
     * How much of one piece points may pay, in percent of its price.
     *
     * @return array{percent: int, source: string, rule: ?array<string, mixed>}
     */
    public function spend(int $productId, int $unit, ?User $user): array
    {
        $facts = $this->facts($productId);
        $share = max(0, min(100, (int) setting('torgash.points', 'spend_max_percent', 50)));
        $answer = fn (string $mode, int $percent): int => match ($mode) {
            'forbidden' => 0,
            'limit' => max(0, min(100, $percent)),
            default => $share,
        };

        if ($facts['outside']) {
            return ['percent' => 0, 'source' => 'outside', 'rule' => null];
        }

        if ($facts['own'] && in_array($facts['own']->spend_mode, self::SPEND_MODES, true)) {
            return ['percent' => $answer($facts['own']->spend_mode, (int) $facts['own']->spend_percent), 'source' => 'product', 'rule' => null];
        }

        foreach ($this->all() as $rule) {
            if (in_array($rule['spend_mode'], self::SPEND_MODES, true) && $this->matches($rule['conditions'], $productId, $unit, $user)) {
                return ['percent' => $answer($rule['spend_mode'], $rule['spend_percent']), 'source' => 'rule', 'rule' => $rule];
            }
        }

        return ['percent' => setting('torgash.points', 'spend_default', true) ? $share : 0, 'source' => 'shop', 'rule' => null];
    }

    public function groupMultiplier(?User $user): float
    {
        $this->groups ??= DB::table('m_points_groups')->pluck('multiplier', 'group_id')->map(fn ($m) => (float) $m)->all();

        return $user?->customer_group_id ? ($this->groups[(int) $user->customer_group_id] ?? 1.0) : 1.0;
    }

    /**
     * Whether everything `$narrow` can match, `$wide` matches too — so a rule
     * with `$wide` standing above one with `$narrow` leaves it nothing.
     *
     * @param  array<string, mixed>  $wide
     * @param  array<string, mixed>  $narrow
     */
    public static function covers(array $wide, array $narrow): bool
    {
        foreach (['products', 'feature_values', 'groups'] as $key) {
            if (isset($wide[$key]) && (! isset($narrow[$key]) || array_diff($narrow[$key], $wide[$key]) !== [])) {
                return false;
            }
        }

        if (isset($wide['categories'])) {
            if (! isset($narrow['categories'])) {
                return false;
            }

            $tree = app(CategoryTree::class);

            foreach ($narrow['categories'] as $id) {
                if (array_intersect(array_map('intval', $tree->ancestors((int) $id, includeSelf: true)), $wide['categories']) === []) {
                    return false;
                }
            }
        }

        return (! isset($wide['price_min']) || (int) ($narrow['price_min'] ?? 0) >= $wide['price_min'])
            && (! isset($wide['price_max']) || (isset($narrow['price_max']) && $narrow['price_max'] <= $wide['price_max']));
    }

    /**
     * Rules nothing can reach: for every question such a rule answers, one
     * above it already answers it for all the same goods.
     *
     * @param  iterable<Rule>  $rules  in the order they are tried
     * @return array<int, Rule> dead rule's id → the rule that stands in its way
     */
    public static function dead(iterable $rules): array
    {
        $above = [];
        $dead = [];

        foreach ($rules as $rule) {
            if (! $rule->enabled) {
                continue;
            }

            $conditions = self::clean((array) $rule->conditions);
            $earns = in_array($rule->earn_mode, self::EARN_MODES, true);
            $pays = in_array($rule->spend_mode, self::SPEND_MODES, true);
            $blocker = null;
            $reachable = false;

            foreach (['earn' => $earns, 'spend' => $pays] as $axis => $speaks) {
                if (! $speaks) {
                    continue;
                }

                $found = null;

                foreach ($above as [$other, $otherConditions, $otherAxes]) {
                    if ($otherAxes[$axis] && self::covers($otherConditions, $conditions)) {
                        $found = $other;

                        break;
                    }
                }

                $found ? $blocker = $found : $reachable = true;
            }

            if ($blocker && ! $reachable) {
                $dead[$rule->id] = $blocker;
            }

            $above[] = [$rule, $conditions, ['earn' => $earns, 'spend' => $pays]];
        }

        return $dead;
    }

    /**
     * Where a new rule goes: just above the first one that would leave it
     * nothing — a subcategory's rule above its category's — else at the end.
     *
     * @param  array<string, mixed>  $conditions
     */
    public static function placeFor(array $conditions): int
    {
        $conditions = self::clean($conditions);
        $rules = Rule::orderBy('position')->orderBy('id')->get();

        foreach ($rules as $rule) {
            if (self::covers(self::clean((array) $rule->conditions), $conditions)) {
                Rule::where('position', '>=', $rule->position)->increment('position');

                return $rule->position;
            }
        }

        return (int) ($rules->max('position') ?? -1) + 1;
    }
}
