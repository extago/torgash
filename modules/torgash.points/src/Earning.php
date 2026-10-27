<?php

namespace Torgash\Points;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Checkout\Models\Order;

/**
 * How many points money earns (PLAN §40.5): "N points for every X" from the
 * settings, times a multiplier — the product's own rule if it has one, else
 * the nearest rule up its category tree (a category is its subcategories),
 * and times the customer group's. 0 anywhere excludes.
 *
 * Counted from the GOODS after every discount, never from delivery, and — by
 * default — only from the part paid with money: a gift card was paid for once
 * already. The rules are a small table read once per request.
 */
class Earning
{
    /** @var array<string, float>|null kind:target → multiplier */
    protected ?array $rules = null;

    /** @var array<int, list<int>> product id → its categories, primed per page */
    protected array $categories = [];

    /**
     * A page of cards announces itself (`catalog.products.listed`): their
     * categories in one query, or "+N points" on a grid is a query per card.
     * Nothing at all when no category carries a rule.
     *
     * @param  iterable<Product>  $products
     */
    public function prime(iterable $products): void
    {
        $ids = [];

        foreach ($products as $product) {
            if ($product instanceof Product && ! isset($this->categories[(int) $product->id])) {
                $ids[] = (int) $product->id;
                $this->categories[(int) $product->id] = [];
            }
        }

        if ($ids === [] || ! $this->hasCategoryRules()) {
            return;
        }

        foreach (DB::table('m_catalog_category_product')->whereIn('product_id', $ids)->get(['product_id', 'category_id']) as $row) {
            $this->categories[(int) $row->product_id][] = (int) $row->category_id;
        }
    }

    protected function hasCategoryRules(): bool
    {
        foreach (array_keys($this->rules()) as $key) {
            if (str_starts_with($key, 'category:')) {
                return true;
            }
        }

        return false;
    }

    /** Points one unit of money earns, before multipliers. */
    public function rate(): float
    {
        $points = (float) setting('torgash.points', 'earn_points', 1);
        $per = (float) setting('torgash.points', 'earn_per', 100) * 100;

        return $per > 0 ? $points / $per : 0.0;
    }

    /** What buying this product at this price earns — "+N points" on a card. */
    public function forPrice(Product $product, int $price, ?User $user = null): int
    {
        return (int) floor($price * $this->rate() * $this->multiplier((int) $product->id, null, $user));
    }

    /**
     * What an order earns: its goods lines, reduced by the order's discount
     * in proportion, reduced again by what a gift card paid when the shop
     * earns on money alone.
     */
    public function forOrder(Order $order): int
    {
        if (! $order->user_id || $this->rate() <= 0) {
            return 0;
        }

        $lines = array_filter((array) $order->items, fn ($line) => is_array($line) && empty($line['parent_key']) && ! empty($line['product_id']));
        $subtotal = max(1, (int) $order->subtotal);
        $afterDiscount = max(0, $subtotal - (int) $order->discount_amount) / $subtotal;
        $money = 1.0;

        if (setting('torgash.points', 'money_only', true) && (int) $order->total > 0) {
            $money = max(0, (int) $order->total - (int) $order->prepaid_total) / (int) $order->total;
        }

        $user = User::find($order->user_id);
        $weighted = 0.0;

        foreach ($lines as $line) {
            $weighted += (int) ($line['line_total'] ?? 0) * $this->multiplier((int) $line['product_id'], null, $user);
        }

        return (int) floor($weighted * $afterDiscount * $money * $this->rate());
    }

    /**
     * The multiplier for one product and one customer.
     *
     * @param  list<int>|null  $categoryIds  the product's categories, when the caller has them
     */
    public function multiplier(int $productId, ?array $categoryIds, ?User $user): float
    {
        $rules = $this->rules();
        $product = $rules['product:'.$productId] ?? null;

        if ($product === null && $this->hasCategoryRules()) {
            $categoryIds ??= $this->categories[$productId]
                ??= DB::table('m_catalog_category_product')->where('product_id', $productId)->pluck('category_id')->map('intval')->all();
            $tree = app(CategoryTree::class);

            foreach ($categoryIds as $categoryId) {
                // The nearest rule up the tree; several categories — the best.
                foreach ($tree->ancestors($categoryId, includeSelf: true) as $id) {
                    if (isset($rules['category:'.$id])) {
                        $product = max($product ?? 0, $rules['category:'.$id]);

                        break;
                    }
                }
            }
        }

        $group = $user?->customer_group_id ? ($rules['group:'.$user->customer_group_id] ?? 1.0) : 1.0;

        return ($product ?? 1.0) * $group;
    }

    /** @return array<string, float> */
    public function rules(): array
    {
        return $this->rules ??= DB::table('m_points_rules')->get()
            ->mapWithKeys(fn ($rule) => [$rule->kind.':'.$rule->target_id => (float) $rule->multiplier])
            ->all();
    }
}
