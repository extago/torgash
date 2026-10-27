<?php

namespace Torgash\Catalog\Support;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/**
 * A closed assortment (PLAN §46): products and categories that only some
 * customers may see — the wholesale range, a club's own shelf.
 *
 * `customer_groups` on a product or a category is a LIST of who may see it:
 * `guest` (not signed in), `registered` (any account), `group:<id>` (a
 * customer group). Empty is everybody, which is every row until the shop says
 * otherwise. A closed CATEGORY closes everything under it — the whole subtree,
 * the rule every category rule in this engine follows — and a product is open
 * only while it AND every category it sits in (with their ancestors) are.
 * Staff see everything: they have to be able to check what a wholesale
 * customer sees.
 *
 * One class answers it for a listing (`narrow()`, one extra condition per
 * query), a category (`categoryOpen()`), a product (`productOpen()`) and the
 * words on the page (`whoFor()`). What an outsider gets on a closed page is
 * the shop's setting `closed_assortment`: `notice` — the name and a photo and
 * "sign in", with no price and no way to buy — or `hide`, not there at all.
 * A per-request singleton: the visitor and the closed categories are read
 * once.
 */
class Assortment
{
    public const GUEST = 'guest';

    public const REGISTERED = 'registered';

    /** @var list<string>|null|false  false = not worked out yet, null = sees everything */
    protected array|null|false $tokens = false;

    /** @var list<int>|null */
    protected ?array $closedCategories = null;

    /**
     * The same answers for a GUEST, whoever is asking — for what has no
     * visitor: the sitemap, a public feed. A product closed to guests is not
     * published to the whole internet because staff happened to open the map.
     */
    public static function asGuest(): self
    {
        $guest = new self;
        $guest->tokens = [self::GUEST];

        return $guest;
    }

    /** Who the visitor is, as the words a list can hold; null = sees everything. */
    public function tokens(): ?array
    {
        if ($this->tokens !== false) {
            return $this->tokens;
        }

        /** @var User|null $user */
        $user = auth()->user();

        if ($user && User::query()->staff()->whereKey($user->id)->exists()) {
            return $this->tokens = null;
        }

        return $this->tokens = $user
            ? array_values(array_filter([self::REGISTERED, $user->customer_group_id ? 'group:'.$user->customer_group_id : null]))
            : [self::GUEST];
    }

    /** Does this list let the visitor in? */
    public function opens(?array $groups): bool
    {
        $tokens = $this->tokens();

        return $tokens === null || empty($groups) || array_intersect($tokens, array_map('strval', $groups)) !== [];
    }

    /** @return list<int> categories closed to the visitor, their subtrees included */
    public function closedCategoryIds(): array
    {
        if ($this->closedCategories !== null) {
            return $this->closedCategories;
        }

        if ($this->tokens() === null) {
            return $this->closedCategories = [];
        }

        $closed = app(CategoryTree::class)->all()
            ->filter(fn (Category $category) => ! $this->opens((array) $category->customer_groups))
            ->pluck('id')->all();

        return $this->closedCategories = $closed === [] ? [] : array_values(array_unique(app(CategoryTree::class)->descendants($closed)));
    }

    public function categoryOpen(Category $category): bool
    {
        return ! in_array((int) $category->id, $this->closedCategoryIds(), true);
    }

    public function productOpen(Product $product): bool
    {
        if (! $this->opens((array) $product->customer_groups)) {
            return false;
        }

        $closed = $this->closedCategoryIds();

        return $closed === [] || ! DB::table('m_catalog_category_product')
            ->where('product_id', $product->id)->whereIn('category_id', $closed)->exists();
    }

    /** A listing, narrowed to what the visitor may see — nothing added when nothing is closed. */
    public function narrow(Builder $query): Builder
    {
        $tokens = $this->tokens();

        if ($tokens === null) {
            return $query;
        }

        $table = $query->getModel()->getTable();
        $closed = $this->closedCategoryIds();

        return $query
            ->where(fn ($q) => $q->whereNull($table.'.customer_groups')
                ->orWhereRaw("jsonb_array_length({$table}.customer_groups) = 0")
                ->orWhereRaw("jsonb_exists_any({$table}.customer_groups, ?::text[])", ['{'.implode(',', $tokens).'}']))
            ->when($closed !== [], fn ($q) => $q->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('m_catalog_category_product as closed_pivot')
                ->whereColumn('closed_pivot.product_id', $table.'.id')
                ->whereIn('closed_pivot.category_id', $closed)));
    }

    /** `hide` — not there at all — or `notice` — named, with "sign in". */
    public function mode(): string
    {
        return setting('torgash.catalog', 'closed_assortment', 'notice') === 'hide' ? 'hide' : 'notice';
    }

    /**
     * Who may see it, in words for the page: "Wholesale", "customers with an
     * account". The product's own list first, else the first closed category's.
     *
     * @param  list<string>|null  $groups
     */
    public function whoFor(?array $groups): string
    {
        $names = [];

        foreach ((array) $groups as $token) {
            $names[] = match (true) {
                $token === self::GUEST => __('visitors without an account'),
                $token === self::REGISTERED => __('customers with an account'),
                str_starts_with((string) $token, 'group:') => (string) \App\Models\CustomerGroup::find((int) substr((string) $token, 6))?->t('name'),
                default => null,
            };
        }

        return implode(', ', array_filter($names));
    }

    /** The list that closes this category: its own, else the nearest ancestor's that does. */
    public function closingGroupsForCategory(Category $category): ?array
    {
        $tree = app(CategoryTree::class);

        foreach ($tree->ancestors((int) $category->id, true) as $id) {
            $row = $tree->find((int) $id);
            if ($row && ! $this->opens((array) $row->customer_groups)) {
                return (array) $row->customer_groups;
            }
        }

        return null;
    }

    /** The list that closes this product to the visitor: its own, else its category's. */
    public function closingGroups(Product $product): ?array
    {
        if (! $this->opens((array) $product->customer_groups)) {
            return (array) $product->customer_groups;
        }

        $closed = $this->closedCategoryIds();
        $tree = app(CategoryTree::class);

        foreach ($product->categories()->pluck('m_catalog_categories.id') as $id) {
            if (! in_array((int) $id, $closed, true)) {
                continue;
            }

            foreach ($tree->ancestors((int) $id, true) as $ancestor) {
                $category = $tree->find((int) (is_object($ancestor) ? $ancestor->id : $ancestor));
                if ($category && ! $this->opens((array) $category->customer_groups)) {
                    return (array) $category->customer_groups;
                }
            }
        }

        return null;
    }

    /**
     * The choices a form offers: nobody-in-particular words first, then each
     * customer group.
     *
     * @return array<string, string>
     */
    public static function choices(): array
    {
        $choices = [self::GUEST => __('Visitors without an account'), self::REGISTERED => __('Any customer with an account')];

        foreach (\App\Models\CustomerGroup::orderBy('id')->get() as $group) {
            $choices['group:'.$group->id] = $group->t('name');
        }

        return $choices;
    }
}
