<?php

namespace Torgash\Promotions;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

/**
 * Does this product (catalog zone) or this cart (cart zone) match a tier's
 * conditions?
 *
 * Two condition vocabularies because the two zones ask about genuinely
 * different things — a product on its own page has no cart, a cart has many
 * products — the same reasoning that keeps shipping's and automations' own
 * condition engines separate rather than shared. Same row shape as both of
 * those (kind/operator/value) so the admin screens read alike.
 */
class Conditions
{
    /** kind => its heading, an English source string for the UI. */
    public const CATALOG_LABELS = [
        'category' => 'Category',
        'product' => 'Product',
        'feature' => 'Feature',
        'price' => 'Price',
        'usergroup' => 'Customer group',
    ];

    public const CATALOG_KINDS = [
        'category' => ['cat_in' => 'is in', 'cat_not_in' => 'is not in'],
        'product' => ['prod_in' => 'is one of', 'prod_not_in' => 'is none of'],
        'feature' => ['feature_is' => 'is'],
        'price' => ['price_gte' => 'is at least', 'price_lte' => 'is at most'],
        // The one catalog condition that asks about the VISITOR rather than
        // the product — "wholesale sees this price, everyone else does not",
        // which is the whole reason a shop runs a catalog-zone promotion at
        // all. Same operator names as the cart zone: the same words about the
        // same thing. A guest belongs to no group, so `is one of` never
        // matches them and `is none of` always does.
        'usergroup' => ['group_in' => 'is one of', 'group_not_in' => 'is none of'],
    ];

    public const CART_LABELS = [
        'subtotal' => 'Cart subtotal',
        'quantity' => 'Item count',
        'category' => 'Product category',
        'product' => 'Product',
        'usergroup' => 'Customer group',
        'payment' => 'Payment method',
    ];

    public const CART_KINDS = [
        'subtotal' => ['subtotal_gte' => 'is at least', 'subtotal_lte' => 'is at most'],
        'quantity' => ['qty_gte' => 'is at least', 'qty_lte' => 'is at most'],
        // Read as "the cart …" — any line's product is/isn't in these.
        'category' => ['cat_has' => 'has an item from', 'cat_has_none' => 'has no item from'],
        'product' => ['cart_has' => 'contains', 'cart_missing' => 'does not contain'],
        'usergroup' => ['group_in' => 'is one of', 'group_not_in' => 'is none of'],
        // Not yet chosen (a live cart before checkout) counts as "not any of
        // these" — a promotion conditioned on a method cannot resolve to
        // "matches" before the customer has picked one.
        'payment' => ['payment_eq' => 'is one of', 'payment_neq' => 'is none of'],
    ];

    /** @param  list<array<string, mixed>>  $rules */
    public static function matchesProduct(array $rules, bool $any, Product $product, ?User $user = null): bool
    {
        if ($rules === []) {
            return true;
        }

        foreach ($rules as $rule) {
            $result = self::passesProduct($rule, $product, $user);

            if ($any && $result) {
                return true;
            }
            if (! $any && ! $result) {
                return false;
            }
        }

        return ! $any;
    }

    protected static function passesProduct(array $rule, Product $product, ?User $user = null): bool
    {
        $ids = self::ids($rule);
        $value = $rule['value'] ?? null;

        return match ($rule['operator'] ?? null) {
            'group_in', 'group_not_in' => self::passesVisitor($rule, $user),
            'cat_in' => $product->categories->pluck('id')->intersect(self::categoryIds($rule))->isNotEmpty(),
            'cat_not_in' => $product->categories->pluck('id')->intersect(self::categoryIds($rule))->isEmpty(),
            'prod_in' => in_array($product->id, $ids, true),
            'prod_not_in' => ! in_array($product->id, $ids, true),
            'feature_is' => $product->featureAssignments->contains(
                fn ($a) => $a->feature_id === (int) ($rule['feature_id'] ?? 0)
                    && $a->feature_value_id === (int) $value,
            ),
            'price_gte' => $product->price >= (int) $value,
            'price_lte' => $product->price <= (int) $value,
            default => false,
        };
    }

    /**
     * The catalog rules that ask about the VISITOR, not the product — the
     * only ones a listing query cannot express, and the reason
     * applyProductQuery() has a PHP half at all. One implementation, called
     * from both sides, so a group rule can never mean one thing to the price
     * and another to the page.
     */
    protected static function passesVisitor(array $rule, ?User $user): bool
    {
        $ids = self::ids($rule);

        return match ($rule['operator'] ?? null) {
            'group_in' => in_array($user?->customer_group_id, $ids, true),
            'group_not_in' => ! in_array($user?->customer_group_id, $ids, true),
            default => false,
        };
    }

    /**
     * The same catalog rules, asked of a QUERY instead of a loaded product —
     * for the sale page, which needs a pageable list rather than a yes/no
     * about one row.
     *
     * Two vocabularies meet here and the split is the whole design: `usergroup`
     * is a rule about the VISITOR, not about any product, so it cannot become
     * a where() clause. It is evaluated once, in PHP, and decides whether the
     * query is asked at all — under `all` a failed visitor rule matches
     * nothing, under `any` a passing one matches everything. Everything else
     * is a plain indexed comparison and stays in SQL.
     *
     * Kept beside matchesProduct() rather than in the model: the two must
     * always agree about what a rule MEANS, and the way to keep them agreeing
     * is to have to change them in the same file.
     *
     * @param  list<array<string, mixed>>  $rules
     */
    public static function applyProductQuery(Builder $query, array $rules, bool $any, ?User $user = null): Builder
    {
        if ($rules === []) {
            // Everything — the sitewide fallback tier. Spelled out rather than
            // returned untouched, because an orWhere() closure that adds no
            // clause is dropped by the query builder, which would silently
            // turn "everything" into "whatever the other tiers said".
            return $query->whereRaw('1 = 1');
        }

        $visitorResults = [];
        $productRules = [];

        foreach ($rules as $rule) {
            if (($rule['kind'] ?? null) === 'usergroup') {
                $visitorResults[] = self::passesVisitor($rule, $user);
            } else {
                $productRules[] = $rule;
            }
        }

        if ($any && in_array(true, $visitorResults, true)) {
            return $query->whereRaw('1 = 1');
        }

        if (! $any && in_array(false, $visitorResults, true)) {
            return $query->whereRaw('1 = 0');
        }

        if ($productRules === []) {
            // Only visitor rules, and they did not settle it above: under
            // `all` they all passed (so everything matches), under `any` they
            // all failed (so nothing does).
            return $query->whereRaw($any ? '1 = 0' : '1 = 1');
        }

        return $query->where(function (Builder $q) use ($productRules, $any) {
            foreach ($productRules as $rule) {
                self::applyProductRule($q, $rule, $any ? 'orWhere' : 'where');
            }
        });
    }

    protected static function applyProductRule(Builder $q, array $rule, string $method): void
    {
        $ids = self::ids($rule);
        $value = (int) ($rule['value'] ?? 0);
        $has = $method === 'orWhere' ? 'orWhereHas' : 'whereHas';
        $hasNot = $method === 'orWhere' ? 'orWhereDoesntHave' : 'whereDoesntHave';

        match ($rule['operator'] ?? null) {
            'cat_in' => $q->{$has}('categories', fn ($c) => $c->whereIn('m_catalog_categories.id', self::categoryIds($rule))),
            'cat_not_in' => $q->{$hasNot}('categories', fn ($c) => $c->whereIn('m_catalog_categories.id', self::categoryIds($rule))),
            'prod_in' => $q->{$method}(fn ($p) => $p->whereIn('m_catalog_products.id', $ids)),
            'prod_not_in' => $q->{$method}(fn ($p) => $p->whereNotIn('m_catalog_products.id', $ids)),
            'feature_is' => $q->{$has}(
                'featureAssignments',
                fn ($f) => $f->where('feature_id', (int) ($rule['feature_id'] ?? 0))
                    ->where('feature_value_id', $value),
            ),
            'price_gte' => $q->{$method}('price', '>=', $value),
            'price_lte' => $q->{$method}('price', '<=', $value),
            // An operator this method does not know must not widen the set:
            // the PHP side answers false for the same rule, and a listing that
            // disagreed with the pricing would advertise a discount the cart
            // then refuses.
            default => $q->{$method}(fn ($p) => $p->whereRaw('1 = 0')),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  list<array{product_id:int, qty:int}>  $lines
     * @param  list<int>  $lineCategoryIds  every category id any line's
     *                                      product belongs to, resolved once by the caller
     */
    public static function matchesCart(
        array $rules,
        bool $any,
        int $subtotal,
        int $quantity,
        array $lines,
        array $lineCategoryIds,
        ?User $user,
        ?string $paymentMethodId = null,
    ): bool {
        if ($rules === []) {
            return true;
        }

        foreach ($rules as $rule) {
            $result = self::passesCart($rule, $subtotal, $quantity, $lines, $lineCategoryIds, $user, $paymentMethodId);

            if ($any && $result) {
                return true;
            }
            if (! $any && ! $result) {
                return false;
            }
        }

        return ! $any;
    }

    protected static function passesCart(
        array $rule,
        int $subtotal,
        int $quantity,
        array $lines,
        array $lineCategoryIds,
        ?User $user,
        ?string $paymentMethodId,
    ): bool {
        $ids = self::ids($rule);

        return match ($rule['operator'] ?? null) {
            'subtotal_gte' => $subtotal >= (int) ($rule['value'] ?? 0),
            'subtotal_lte' => $subtotal <= (int) ($rule['value'] ?? 0),
            'qty_gte' => $quantity >= (int) ($rule['value'] ?? 0),
            'qty_lte' => $quantity <= (int) ($rule['value'] ?? 0),
            'cat_has' => (bool) array_intersect($lineCategoryIds, self::categoryIds($rule)),
            'cat_has_none' => ! array_intersect($lineCategoryIds, self::categoryIds($rule)),
            'cart_has' => collect($lines)->pluck('product_id')->intersect($ids)->isNotEmpty(),
            'cart_missing' => collect($lines)->pluck('product_id')->intersect($ids)->isEmpty(),
            'group_in' => in_array($user?->customer_group_id, $ids, true),
            'group_not_in' => ! in_array($user?->customer_group_id, $ids, true),
            'payment_eq' => $paymentMethodId !== null && in_array((int) $paymentMethodId, $ids, true),
            'payment_neq' => $paymentMethodId === null || ! in_array((int) $paymentMethodId, $ids, true),
            default => false,
        };
    }

    /** @return list<int> */
    protected static function ids(array $rule): array
    {
        return array_values(array_filter(array_map('intval', (array) ($rule['ids'] ?? []))));
    }

    /**
     * The same ids, plus everything under them.
     *
     * Separate from ids() because that one also answers for products, customer
     * groups and payment methods, none of which is a tree. A promotion on
     * "Electronics" means the whole branch — see Catalog\Support\CategoryTree
     * for why that is the rule everywhere rather than a switch here.
     *
     * @return list<int>
     */
    protected static function categoryIds(array $rule): array
    {
        return app(CategoryTree::class)->descendants(self::ids($rule));
    }

    /**
     * A tier's conditions in plain words, for the promotions list screen.
     *
     * @param  array<int, string>  $categoryNames
     * @param  array<int, string>  $productNames
     */
    public static function describe(array $rules, string $zone, array $categoryNames = [], array $productNames = []): string
    {
        $labels = $zone === 'catalog' ? self::CATALOG_LABELS : self::CART_LABELS;
        $kinds = $zone === 'catalog' ? self::CATALOG_KINDS : self::CART_KINDS;

        $lines = [];
        foreach ($rules as $rule) {
            $kind = $rule['kind'] ?? '';
            $operator = $rule['operator'] ?? '';
            $kindLabel = __($labels[$kind] ?? $kind);
            $opLabel = __($kinds[$kind][$operator] ?? $operator);

            $value = match (true) {
                in_array($kind, ['category', 'product', 'payment'], true) => self::nameList(
                    self::ids($rule),
                    $kind === 'category' ? $categoryNames : $productNames,
                ),
                $kind === 'price' => admin_price((int) ($rule['value'] ?? 0)),
                $kind === 'subtotal' => admin_price((int) ($rule['value'] ?? 0)),
                $kind === 'quantity' => (string) ($rule['value'] ?? ''),
                default => '',
            };

            $lines[] = trim("{$kindLabel} — {$opLabel} {$value}");
        }

        // Not __('Everything') — that source string is already claimed by the
        // permission-groups screen ("all permissions" → "Все права"), and a
        // JSON translation collides on the exact English string regardless of
        // context, so a shared generic word silently prints the wrong sentence.
        return $lines === [] ? __('matches everything') : implode('; ', $lines);
    }

    /** @param list<int> $ids @param array<int,string> $names */
    protected static function nameList(array $ids, array $names): string
    {
        if ($ids === []) {
            return '';
        }

        $known = array_filter(array_map(fn (int $id) => $names[$id] ?? null, $ids));

        return $known !== [] ? implode(', ', $known) : __(':count selected', ['count' => count($ids)]);
    }
}
