<?php

namespace Torgash\Age;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;

/**
 * The shop's age limits (PLAN §71) — facts about goods, the same for every
 * visitor: an age on a category (it holds everything under it, the rule
 * every category rule in the engine follows), on a product, or on the whole
 * shop (`site_age`), each with what a visitor who has not said they are old
 * enough is shown (`display`, null — the shop's default). Several at once
 * fold into one `Limit`.
 *
 * A per-request singleton. One query tells it the category rows (they are
 * few) and whether any product has a row of its own; a product's own row is
 * read for a whole page of products at once (`prime()`, on
 * catalog.products.listed), and the categories a product sits in are the
 * ones the catalogue loaded for that page — never a query per card. A shop
 * with no limits pays that one query, on a page that asks.
 */
class Rules
{
    /** @var array<int, array{age: int, display: ?string}>|null category id => its own row */
    protected ?array $categoryRows = null;

    protected int $oldestProductAge = 0;

    /** @var array<int, Limit>|null category id => its limit, its ancestors' included */
    protected ?array $categoryLimits = null;

    /** @var array<int, Limit|null> product id => its own rule (null: none) */
    protected array $productRows = [];

    /** @var array<int, list<int>> product id => the categories it sits in */
    protected array $productCategories = [];

    /** What a form offers for a rule's showing: the shop's default first. */
    public static function displayChoices(): array
    {
        $default = self::displayLabels()[app(self::class)->defaultDisplay()];

        return ['' => __('As in the settings: :choice', ['choice' => mb_strtolower($default)])] + self::displayLabels();
    }

    /** What a visitor who has not confirmed their age is shown, in a few words. @return array<string, string> */
    public static function displayLabels(): array
    {
        return [
            Limit::SHOW => __('Shown as usual'),
            Limit::COVER => __('Photos covered'),
            Limit::HIDE => __('Not in lists or search'),
        ];
    }

    public function defaultDisplay(): string
    {
        $display = (string) setting('torgash.age', 'display', Limit::COVER);

        return in_array($display, Limit::KINDS, true) ? $display : Limit::COVER;
    }

    /** A row's own showing, or the shop's default for none. */
    public function display(?string $display): string
    {
        return in_array((string) $display, Limit::KINDS, true) ? (string) $display : $this->defaultDisplay();
    }

    public static function clampAge(mixed $age): int
    {
        return is_numeric($age) ? max(0, min(99, (int) $age)) : 0;
    }

    /** The whole shop's rule, if it has one. */
    public function site(): ?Limit
    {
        return Limit::of(self::clampAge(setting('torgash.age', 'site_age', 0)), $this->defaultDisplay());
    }

    /** @return array<int, array{age: int, display: ?string}> */
    public function categoryRows(): array
    {
        if ($this->categoryRows !== null) {
            return $this->categoryRows;
        }

        $rows = [];

        // The category rows and, in the same breath, the oldest age any
        // product row sets — 0 is "no product has an age of its own", which
        // spares every product the question.
        foreach (DB::select('select category_id as id, age, display from m_age_category_limits
            union all select -1, coalesce(max(age), 0), null from m_age_product_limits') as $row) {
            if ((int) $row->id === -1) {
                $this->oldestProductAge = (int) $row->age;
            } else {
                $rows[(int) $row->id] = ['age' => (int) $row->age, 'display' => $row->display];
            }
        }

        return $this->categoryRows = $rows;
    }

    public function anyProductRows(): bool
    {
        $this->categoryRows();

        return $this->oldestProductAge > 0;
    }

    /** Is there any age anywhere in the shop? */
    public function any(): bool
    {
        return $this->site() !== null || $this->categoryRows() !== [] || $this->anyProductRows();
    }

    /** The oldest age any rule of the shop sets — what "every page" asks for. */
    public function oldestAge(): int
    {
        $age = $this->site()?->age() ?? 0;

        foreach ($this->categoryRows() as $row) {
            $age = max($age, $row['age']);
        }

        return max($age, $this->oldestProductAge);
    }

    /** @return array<int, Limit> every category an age reaches, its subtree included */
    public function categoryLimits(): array
    {
        if ($this->categoryLimits !== null) {
            return $this->categoryLimits;
        }

        $tree = app(CategoryTree::class);
        $limits = [];

        foreach ($this->categoryRows() as $id => $row) {
            $rule = Limit::of($row['age'], $this->display($row['display']));

            foreach ($tree->descendants($id) as $under) {
                $limits[(int) $under] = Limit::merge($limits[(int) $under] ?? null, $rule);
            }
        }

        return $this->categoryLimits = $limits;
    }

    /** A category's limit: the shop's, its own and its ancestors'. */
    public function forCategory(Category|int|null $category): ?Limit
    {
        $id = $category instanceof Category ? (int) $category->id : (int) $category;

        return Limit::merge($this->site(), $id > 0 ? ($this->categoryLimits()[$id] ?? null) : null);
    }

    /**
     * Read what a page of products needs at once: their own rows, and the
     * categories with an age they sit in where the catalogue has not loaded
     * their categories already. Called on catalog.products.listed; a product
     * nobody announced is read alone.
     *
     * @param  iterable<Product|int>  $products
     */
    public function prime(iterable $products): void
    {
        $rows = [];
        $links = [];
        $limited = $this->categoryLimits();

        foreach ($products as $product) {
            $id = (int) ($product instanceof Product ? $product->id : $product);

            if ($id <= 0) {
                continue;
            }

            if (! array_key_exists($id, $this->productRows)) {
                $rows[$id] = $id;
                $this->productRows[$id] = null;
            }

            if ($limited !== [] && ! array_key_exists($id, $this->productCategories)) {
                if ($product instanceof Product && $product->relationLoaded('categories')) {
                    $this->productCategories[$id] = $product->categories->pluck('id')->map(fn ($categoryId) => (int) $categoryId)->all();
                } else {
                    $links[$id] = $id;
                    $this->productCategories[$id] = [];
                }
            }
        }

        if ($rows !== [] && $this->anyProductRows()) {
            foreach (array_chunk(array_values($rows), 1000) as $chunk) {
                foreach (DB::table('m_age_product_limits')->whereIn('product_id', $chunk)->get() as $row) {
                    $this->productRows[(int) $row->product_id] = Limit::of((int) $row->age, $this->display($row->display));
                }
            }
        }

        foreach (array_chunk(array_values($links), 1000) as $chunk) {
            foreach (DB::table('m_catalog_category_product')->whereIn('product_id', $chunk)
                ->whereIn('category_id', array_keys($limited))->get(['product_id', 'category_id']) as $link) {
                $this->productCategories[(int) $link->product_id][] = (int) $link->category_id;
            }
        }
    }

    /**
     * The products whose pictures a visitor who has said nothing does not see
     * — covered or left out — among all of them: null for every product (the
     * whole shop covers). What a search engine is not handed either.
     *
     * @return list<int>|null
     */
    public function coveredForGuests(): ?array
    {
        if (($this->site()?->liftAge() ?? 0) > 0) {
            return null;
        }

        $ids = [];
        $categories = array_keys(array_filter($this->categoryLimits(), fn (Limit $limit) => $limit->liftAge() > 0));

        if ($categories !== []) {
            $ids = DB::table('m_catalog_category_product')->whereIn('category_id', $categories)
                ->distinct()->pluck('product_id')->map(fn ($id) => (int) $id)->all();
        }

        if ($this->anyProductRows()) {
            $kinds = $this->defaultDisplay() === Limit::SHOW ? [Limit::COVER, Limit::HIDE] : null;

            $own = DB::table('m_age_product_limits')
                ->when($kinds !== null,
                    fn ($query) => $query->whereIn('display', $kinds),
                    fn ($query) => $query->where(fn ($kind) => $kind->whereNull('display')->orWhere('display', '!=', Limit::SHOW)))
                ->pluck('product_id')->map(fn ($id) => (int) $id)->all();

            $ids = array_values(array_unique([...$ids, ...$own]));
        }

        return $ids;
    }

    /** A product's own rule alone (its form shows it apart from what it inherits). */
    public function ownRow(int $productId): ?array
    {
        $row = DB::table('m_age_product_limits')->where('product_id', $productId)->first();

        return $row ? ['age' => (int) $row->age, 'display' => $row->display] : null;
    }

    /** A product's limit: the shop's, its own, and every category it sits in. */
    public function forProduct(Product|int $product): ?Limit
    {
        $id = (int) ($product instanceof Product ? $product->id : $product);

        if ($id <= 0) {
            return $this->site();
        }

        $categories = $this->categoryLimits();

        if (! array_key_exists($id, $this->productRows) || ($categories !== [] && ! array_key_exists($id, $this->productCategories))) {
            $this->prime([$product]);
        }

        $limit = Limit::merge($this->site(), $this->productRows[$id] ?? null);

        foreach ($this->productCategories[$id] ?? [] as $categoryId) {
            $limit = Limit::merge($limit, $categories[$categoryId] ?? null);
        }

        return $limit;
    }

    /**
     * The oldest age these products ask for — 0, none. What the order asks
     * the buyer to tick and the note it carries.
     *
     * @param  iterable<Product|int>  $products
     */
    public function ageOf(iterable $products): int
    {
        $products = is_array($products) ? $products : iterator_to_array($products, false);

        if (! $this->any()) {
            return 0;
        }

        $this->prime($products);
        $age = 0;

        foreach ($products as $product) {
            $age = max($age, $this->forProduct($product)?->age() ?? 0);
        }

        return $age;
    }

    /**
     * A listing without the goods hidden from a visitor who has said they
     * are $confirmed — nothing added when nothing hides.
     */
    public function narrow(Builder $query, int $confirmed): Builder
    {
        if (($this->site()?->hide ?? 0) > $confirmed) {
            return $query->whereRaw('false');
        }

        $table = $query->getModel()->getTable();
        $hidden = array_keys(array_filter($this->categoryLimits(), fn (Limit $limit) => $limit->hide > $confirmed));

        if ($hidden !== []) {
            $query->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('m_catalog_category_product as age_pivot')
                ->whereColumn('age_pivot.product_id', $table.'.id')
                ->whereIn('age_pivot.category_id', $hidden));
        }

        if ($this->anyProductRows()) {
            $hideByDefault = $this->defaultDisplay() === Limit::HIDE;

            $query->whereNotExists(fn ($sub) => $sub->select(DB::raw(1))
                ->from('m_age_product_limits as age_own')
                ->whereColumn('age_own.product_id', $table.'.id')
                ->where('age_own.age', '>', $confirmed)
                ->where(fn ($kind) => $kind->where('age_own.display', Limit::HIDE)
                    ->when($hideByDefault, fn ($kind) => $kind->orWhereNull('age_own.display')
                        ->orWhereNotIn('age_own.display', Limit::KINDS))));
        }

        return $query;
    }
}
