<?php

namespace Torgash\Catalog\Content;

use App\Core\Content\Placeholders;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\FeatureValue;
use Torgash\Catalog\Models\FilterPage;
use Torgash\Catalog\Models\PriceType;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\ShortDescription;

/**
 * What an admin-written block may say about the product it is standing next to.
 *
 * The point is the yellow club-price row on the product page: it was markup in
 * `product.blade.php`, so the only way to change a word of it was to edit a
 * template. With these tokens the whole row is an HTML block — the price, the
 * name of the price type, and a popup explaining it, arranged by whoever owns
 * the shop rather than by whoever owns the theme.
 *
 * Every token answers from `$context['product']`, which the blocks module hands
 * over from the zone it is rendering. On a page with no product they resolve to
 * an empty string, which is exactly what the HTML block's `hide_if_empty` acts
 * on — so a block written for the product page does not draw an empty frame on
 * the home page.
 */
class ProductPlaceholders
{
    public function register(Placeholders $placeholders): void
    {
        $group = 'product';

        $this->registerPageTokens($placeholders);

        $placeholders->register('product_name', [
            'label' => 'Product name',
            'group' => $group,
            'resolve' => fn (array $context = []) => $this->product($context)?->t('name') ?? '',
        ]);

        $placeholders->register('product_sku', [
            'label' => 'Product SKU',
            'group' => $group,
            'resolve' => fn (array $context = []) => $this->product($context)?->sku ?? '',
        ]);

        $placeholders->register('price', [
            'label' => 'Price the visitor pays',
            'group' => $group,
            'resolve' => function (array $context = []) {
                $product = $this->product($context);

                // Through the same filter the page itself uses, so a tax module
                // that adds tax to displayed prices is not bypassed by writing
                // the price into a block.
                return $product
                    ? format_price(hook_filter('catalog.price.display', $product->effectivePrice(), ['product' => $product]))
                    : '';
            },
        ]);

        $placeholders->register('old_price', [
            'label' => 'Crossed-out price',
            'group' => $group,
            'resolve' => function (array $context = []) {
                $old = $this->product($context)?->oldPrice();

                return $old ? format_price($old) : '';
            },
        ]);

        $placeholders->register('discount_percent', [
            'label' => 'Discount, %',
            'group' => $group,
            'resolve' => fn (array $context = []) => (string) ($this->product($context)?->discountPercent() ?: ''),
        ]);

        // The club price and its name: the two halves of the row this exists
        // for. Empty when the shop advertises no such price type, or when it is
        // not actually cheaper than what the visitor already sees — the same
        // condition the built-in row applies, kept in one place.
        $placeholders->register('club_price', [
            'label' => 'Advertised club price',
            'group' => $group,
            'resolve' => function (array $context = []) {
                $found = $this->club($context);

                return $found ? format_price($found['price']) : '';
            },
        ]);

        $placeholders->register('club_price_name', [
            'label' => 'Club price name',
            'group' => $group,
            'resolve' => fn (array $context = []) => (($this->club($context) ?? [])['type'] ?? null)?->t('name') ?? '',
        ]);

        $placeholders->register('club_price_note', [
            'label' => 'Club price note',
            'group' => $group,
            'resolve' => fn (array $context = []) => (($this->club($context) ?? [])['type'] ?? null)?->t('storefront_note') ?? '',
        ]);
    }

    /**
     * What a page is ABOUT, for a title or a meta description written once and
     * applied to a thousand products (PLAN §30.5).
     *
     * The same registry as everything else, so they work in an HTML block too
     * and appear in the same picker. They answer from whichever of `product`,
     * `category` and `brand` the caller put in the context, and a product page
     * can answer the category ones as well: a product knows which category it
     * is in, and "Buy {product} in {category}" is the commonest title there is.
     */
    protected function registerPageTokens(Placeholders $placeholders): void
    {
        $group = 'page';

        $placeholders->register('product', [
            'label' => 'Product name',
            'group' => $group,
            'resolve' => fn (array $context = []) => $this->product($context)?->t('name') ?? '',
        ]);

        // Typed or composed, exactly as the page prints it — a template asking
        // for it must not get a different answer from the one the visitor reads.
        $placeholders->register('short_description', [
            'label' => 'Short description',
            'group' => $group,
            'resolve' => function (array $context = []) {
                $product = $this->product($context);

                return $product ? app(ShortDescription::class)->for($product) : '';
            },
        ]);

        $placeholders->register('category', [
            'label' => 'Category name',
            'group' => $group,
            'resolve' => fn (array $context = []) => $this->category($context)?->t('name') ?? '',
        ]);

        // The trail, both ways round. Forwards reads as a path and backwards as
        // a title ("Gaming laptops — Laptops — Electronics"), which is why
        // Other engines ship both and so do we.
        // The separator is the ARGUMENT rather than a setting: others keep it
        // in a language variable, which means one answer for the whole shop —
        // and a title wants " — " where a breadcrumb line wants " / ". Written
        // once per template, where the reader can see it.
        $placeholders->register('category_path', [
            'label' => 'Category path',
            'group' => $group,
            'argument' => ['label' => 'separator', 'example' => ' / '],
            'resolve' => fn (array $context = [], ?string $separator = null) => implode($separator ?? ' / ', $this->path($context)),
        ]);

        $placeholders->register('category_path_reverse', [
            'label' => 'Category path, innermost first',
            'group' => $group,
            'argument' => ['label' => 'separator', 'example' => ' / '],
            'resolve' => fn (array $context = [], ?string $separator = null) => implode($separator ?? ' / ', array_reverse($this->path($context))),
        ]);

        $placeholders->register('brand', [
            'label' => 'Brand',
            'group' => $group,
            'resolve' => fn (array $context = []) => $this->brand($context),
        ]);

        // Prices of the SET the page is about: one product's own price, or the
        // cheapest and dearest in a category. `publicPrice()` all the way down —
        // a template is written once and read by everybody, so it must not
        // quote the price of whoever happened to be logged in.
        $placeholders->register('min_price', [
            'label' => 'Lowest price on the page',
            'group' => $group,
            'resolve' => fn (array $context = []) => $this->bound($context, 'min'),
        ]);

        $placeholders->register('max_price', [
            'label' => 'Highest price on the page',
            'group' => $group,
            'resolve' => fn (array $context = []) => $this->bound($context, 'max'),
        ]);

        $placeholders->register('total_products', [
            'label' => 'How many products',
            'group' => $group,
            'resolve' => fn (array $context = []) => (string) ($this->countProducts($context) ?: ''),
        ]);

        // What a filter page is filtered BY, and the characteristic it is
        // filtered on — the two words a title for such a page is made of
        // ("Apple laptops", "Laptops by memory"). Empty everywhere else, which
        // is what the `[optional fragment]` syntax acts on.
        $placeholders->register('filter', [
            'label' => 'What the filter page selects',
            'group' => $group,
            'example' => '{filter}',
            'resolve' => fn (array $context = []) => $this->filterValues($context)
                ->map(fn (FeatureValue $v) => $v->display())->filter()->implode(', '),
        ]);

        $placeholders->register('filter_feature', [
            'label' => 'The characteristic the page filters on',
            'group' => $group,
            'resolve' => fn (array $context = []) => $this->filterValues($context)
                ->map(fn (FeatureValue $v) => $v->feature?->t('name'))->filter()->unique()->implode(', '),
        ]);

        // One characteristic by its code — the token that makes a template about
        // a catalogue rather than about a shop. Empty when the product has no
        // such value, which is what the `[optional fragment]` syntax acts on.
        $placeholders->register('feature', [
            'label' => 'A characteristic, by its code',
            'group' => $group,
            'argument' => ['label' => 'code', 'example' => 'ram_gb'],
            'example' => '{feature:ram_gb}',
            'resolve' => fn (array $context = [], ?string $code = null) => $this->feature($context, $code),
        ]);

        // Its NAME, for a template that writes the pair out ("{feature_name:ram_gb}:
        // {feature:ram_gb}"). Empty when the product has no such value — not
        // when the characteristic merely exists — so the optional fragment
        // around the pair disappears as one, which is the whole point of
        // shipping a name token beside a value token.
        $placeholders->register('feature_name', [
            'label' => 'A characteristic\'s name, by its code',
            'group' => $group,
            'argument' => ['label' => 'code', 'example' => 'ram_gb'],
            'example' => '{feature_name:ram_gb}',
            'resolve' => fn (array $context = [], ?string $code = null) => $this->featureName($context, $code),
        ]);

        // The page's OWN price boundary (PLAN §30.14) — not `{min_price}` /
        // `{max_price}` above, which ask what the products on the page cost.
        // Bare numbers, no currency sign: the same reason ProductText never
        // calls format_price(), and the address these feed a slug from may not
        // move with the customer's session currency.
        $placeholders->register('price_min', [
            'label' => 'The page\'s price floor',
            'group' => $group,
            'example' => '{price_min}',
            'resolve' => fn (array $context = []) => $this->priceBoundToken($context, 'price_min'),
        ]);

        $placeholders->register('price_max', [
            'label' => 'The page\'s price ceiling',
            'group' => $group,
            'example' => '{price_max}',
            'resolve' => fn (array $context = []) => $this->priceBoundToken($context, 'price_max'),
        ]);
    }

    /**
     * The category the page is about: the one being rendered, or — on a product
     * page — the first category the visitor could actually have arrived
     * through. A disabled category has no page, so it cannot be what a title
     * says the product is in.
     *
     * @param  array<string, mixed>  $context
     */
    protected function category(array $context): ?Category
    {
        $category = $context['category'] ?? null;

        if ($category instanceof Category) {
            return $category;
        }

        // A filter page is a category page with fewer products in it, and a
        // template written for categories reads correctly on one.
        if ($page = $this->filterPage($context)) {
            return $page->category;
        }

        return $this->product($context)?->categories->first(fn (Category $c) => $c->isListed());
    }

    /**
     * The values a filter page stands for, from whichever end the caller has
     * them: the generator is naming a page it has not saved yet and hands the
     * values over directly, a template is rendering one that exists.
     *
     * @param  array<string, mixed>  $context
     * @return Collection<int, FeatureValue>
     */
    protected function filterValues(array $context): Collection
    {
        $given = $context['filter_values'] ?? null;

        if ($given instanceof Collection) {
            return $given;
        }

        return $this->filterPage($context)?->featureValues() ?? collect();
    }

    /** @param array<string, mixed> $context */
    protected function filterPage(array $context): ?FilterPage
    {
        $page = $context['filter_page'] ?? $context['subject'] ?? null;

        return $page instanceof FilterPage ? $page : null;
    }

    /**
     * `{price_min}`/`{price_max}`, from whichever end the caller has them: the
     * generator is naming a page it has not saved yet and hands the minor-unit
     * bound over directly in the context, a template rendering an existing page
     * reads its own column. Empty when there is no such bound, which is what
     * the `[optional fragment]` syntax needs to drop "от {price_min}" whole.
     *
     * @param  array<string, mixed>  $context
     */
    protected function priceBoundToken(array $context, string $key): string
    {
        if (array_key_exists($key, $context)) {
            return $context[$key] === null ? '' : FilterPage::bareAmount((int) $context[$key]);
        }

        $page = $this->filterPage($context);
        $column = $key === 'price_min' ? $page?->price_min : $page?->price_max;

        return $column === null ? '' : FilterPage::bareAmount((int) $column);
    }

    /**
     * The trail as words, outermost first.
     *
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    protected function path(array $context): array
    {
        $category = $this->category($context);

        if (! $category) {
            return [];
        }

        $names = array_map(fn (Category $c) => (string) $c->t('name'), $category->ancestors());
        $names[] = (string) $category->t('name');

        return array_values(array_filter($names));
    }

    /** @param array<string, mixed> $context */
    protected function brand(array $context): string
    {
        $brand = $context['brand'] ?? null;

        if ($brand instanceof FeatureValue) {
            return (string) $brand->display();
        }

        foreach ($this->product($context)?->featureAssignments ?? [] as $assignment) {
            if ($assignment->feature?->purpose === 'brand') {
                return (string) $assignment->display();
            }
        }

        return '';
    }

    /** @param array<string, mixed> $context */
    protected function feature(array $context, ?string $code): string
    {
        $product = $this->product($context);

        if (! $product || ! $code) {
            return '';
        }

        foreach ($product->featureAssignments as $assignment) {
            if ($assignment->feature?->code === $code) {
                return (string) $assignment->display();
            }
        }

        // ...and the free-form ones, which are a plain map keyed by whatever the
        // shop typed. A catalogue fills one or the other, rarely both.
        $value = $product->features[$code] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The characteristic's own name — answered only where the product actually
     * has a value, so a pair written as "{feature_name:x}: {feature:x}" is
     * empty or complete and never a label with nothing after it.
     *
     * @param  array<string, mixed>  $context
     */
    protected function featureName(array $context, ?string $code): string
    {
        $product = $this->product($context);

        if (! $product || ! $code) {
            return '';
        }

        foreach ($product->featureAssignments as $assignment) {
            if ($assignment->feature?->code === $code && $assignment->display() !== null) {
                return (string) $assignment->feature->t('name');
            }
        }

        // A free-form characteristic IS its key: there is no row to carry a
        // name, and the key is what the admin typed as one.
        $value = $product->features[$code] ?? null;

        return is_scalar($value) ? $code : '';
    }

    /**
     * The cheapest or dearest price on the page, formatted.
     *
     * @param  array<string, mixed>  $context
     */
    protected function bound(array $context, string $which): string
    {
        if ($product = $this->product($context)) {
            return format_price($product->publicPrice());
        }

        // A filter page's prices are the prices of what IS on it: a title
        // saying "from $399" under a page whose cheapest item is $899 is the
        // kind of wrong that only shows up in a search result.
        $query = $this->setQuery($context);

        if (! $query) {
            return '';
        }

        $price = $query->toBase()->{$which}('price');

        return $price === null ? '' : format_price((int) $price);
    }

    /**
     * The products the PAGE is about — a filter page's narrower set, or the
     * category's, or nothing.
     *
     * @param  array<string, mixed>  $context
     */
    protected function setQuery(array $context): ?Builder
    {
        $category = $this->category($context);

        if (! $category) {
            return null;
        }

        $query = Product::listed()
            ->whereHas('categories', fn ($q) => $q->where('m_catalog_categories.id', $category->id));

        return ($page = $this->filterPage($context)) ? $page->applyTo($query) : $query;
    }

    /** @param array<string, mixed> $context */
    protected function countProducts(array $context): int
    {
        return $this->setQuery($context)?->count() ?? 0;
    }

    /** @param array<string, mixed> $context */
    protected function product(array $context): ?Product
    {
        $product = $context['product'] ?? null;

        return $product instanceof Product ? $product : null;
    }

    /**
     * The cheapest advertised price type that beats what the visitor is already
     * being shown, or null.
     *
     * @param  array<string, mixed>  $context
     * @return array{type: PriceType, price: int}|null
     */
    protected function club(array $context): ?array
    {
        $product = $this->product($context);

        if (! $product) {
            return null;
        }

        $shown = hook_filter('catalog.price.display', $product->effectivePrice(), ['product' => $product]);

        foreach (app(\Torgash\Catalog\Support\PriceTypes::class)->shownOnStorefront() as $type) {
            $price = $product->priceForType($type);

            if ($price < $shown) {
                return ['type' => $type, 'price' => $price];
            }
        }

        return null;
    }
}
