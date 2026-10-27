<?php

namespace Torgash\Wishlist\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Filters\Flags;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\CategoryTree;
use Torgash\Wishlist\Models\WishList;
use Torgash\Wishlist\Wishlists;

/**
 * The list page (PLAN §35.3, the owner's mock-up): a category — with
 * everything under it, a category being its subcategories —, a search by
 * name or SKU, "in stock only" and "on sale only" through the catalogue's OWN
 * flags (so they mean here what they mean in a category), "clear", and each
 * row priced, stocked and rated by the catalogue's code, with whatever a
 * module offers to do with it (the basket's button) and a way to remove it.
 *
 * The heart itself: one form that toggles, answered with the heart in its new
 * state plus the header counter out of band; without JavaScript, back to the
 * page it was pressed on.
 */
class WishlistController extends Controller
{
    public function index(Request $request, Wishlists $wishlists, CategoryTree $tree)
    {
        $ids = array_keys($wishlists->ids());
        $categoryId = (int) $request->query('category', 0);
        $q = trim(mb_substr((string) $request->query('q', ''), 0, 100));

        $query = Product::reachable()->withCardData()
            ->join('m_wishlist_items as wi', 'wi.product_id', '=', 'm_catalog_products.id')
            ->where('wi.list_id', $wishlists->current()?->id ?? 0)
            ->select('m_catalog_products.*', 'wi.created_at as wished_at', 'wi.price_at_add');

        if ($categoryId > 0) {
            $query->whereIn('m_catalog_products.id', DB::table('m_catalog_category_product')
                ->whereIn('category_id', $tree->descendants($categoryId))->select('product_id'));
        }

        if ($q !== '') {
            $like = '%'.addcslashes(mb_strtolower($q), '\\%_').'%';
            $query->where(fn ($w) => $w->whereRaw('lower(m_catalog_products.sku) like ?', [$like])
                ->orWhereRaw('exists (select 1 from jsonb_each_text(m_catalog_products.name) n(k, v) where lower(n.v) like ?)', [$like]));
        }

        $flags = array_values(array_filter([
            $request->boolean('in_stock') ? 'in_stock' : null,
            $request->boolean('on_sale') ? 'on_sale' : null,
        ]));
        app(Flags::class)->applyKeys($query, $flags);

        $products = $query->orderByDesc('wi.created_at')->orderByDesc('m_catalog_products.id')->paginate(20)->withQueryString();

        // Announced like any grid: the basket's buttons, the ratings and the
        // stock of a whole page answer in one query each.
        hook_action('catalog.products.listed', ['products' => $products->getCollection()]);

        page_meta()->set('title', __('Wishlist'));
        breadcrumbs()->add(__('Wishlist'));

        return view('torgash.wishlist::index', [
            'products' => $products,
            'total' => count($ids),
            'list' => $wishlists->current(),
            'categoryOptions' => $this->categoryOptions($ids, $tree),
            'paths' => $this->paths($products->getCollection()->pluck('id')->all(), $tree),
            'filters' => ['category' => $categoryId, 'q' => $q, 'in_stock' => $request->boolean('in_stock'), 'on_sale' => $request->boolean('on_sale')],
        ]);
    }

    public function toggle(Request $request, Product $product, Wishlists $wishlists)
    {
        abort_unless(Product::reachable()->whereKey($product->id)->exists(), 404);

        $wishlists->toggle($product);

        if (! $request->header('HX-Request')) {
            return back();
        }

        return response()->view('torgash.wishlist::toggled', [
            'wishProduct' => $product,
            'wishLabelled' => $request->boolean('labelled'),
            'wishCompact' => $request->boolean('compact'),
            // Only an answer to a click plays the little pop; the same heart
            // drawn with the page stays still.
            'wishJust' => true,
        ]);
    }

    /** The × on a row of the list: the row goes, the counter follows. */
    public function remove(Request $request, Product $product, Wishlists $wishlists)
    {
        $wishlists->remove($product->id);

        if (! $request->header('HX-Request')) {
            return back();
        }

        return response()->view('torgash.wishlist::removed');
    }

    public function clear(Wishlists $wishlists)
    {
        $wishlists->clear();

        return redirect()->route('wishlist.index')->with('status', __('The wishlist is empty now.'));
    }

    /**
     * The two letters' switches. Only an account can switch them on — a
     * letter needs an address — so a guest posting by hand changes nothing.
     */
    public function notify(Request $request, Wishlists $wishlists)
    {
        abort_unless(auth()->check() && setting('torgash.wishlist', 'offer_notifications'), 403);

        $list = $wishlists->current();
        $price = $request->has('notify_price') ? $request->boolean('notify_price') : (bool) $list?->notify_price;
        $stock = $request->has('notify_stock') ? $request->boolean('notify_stock') : (bool) $list?->notify_stock;
        $wishlists->setNotify($price, $stock);

        return back()->with('status', __('Saved.'));
    }

    public function share(Request $request, Wishlists $wishlists)
    {
        abort_unless(setting('torgash.wishlist', 'share'), 404);

        $wishlists->share(renew: $request->boolean('renew'));

        return back()->with('status', $request->boolean('renew')
            ? __('A new link is ready; the old one no longer opens the list.')
            : __('The link to your list is ready.'));
    }

    public function unshare(Wishlists $wishlists)
    {
        $wishlists->unshare();

        return back()->with('status', __('Nobody can open the list by a link now.'));
    }

    /**
     * Somebody else's list, by its link: to look at and to buy from, nothing
     * else — no name, no address, no dates, and no way to change it. A link
     * taken back (or never issued) answers 404, as a list that never existed.
     */
    public function shared(string $token, Request $request)
    {
        abort_unless(setting('torgash.wishlist', 'share'), 404);

        $list = WishList::where('share_token', $token)->first();
        abort_unless($list, 404);

        $products = Product::reachable()->withCardData()
            ->join('m_wishlist_items as wi', 'wi.product_id', '=', 'm_catalog_products.id')
            ->where('wi.list_id', $list->id)
            ->select('m_catalog_products.*')
            ->orderByDesc('wi.created_at')->orderByDesc('m_catalog_products.id')
            ->paginate(20);

        hook_action('catalog.products.listed', ['products' => $products->getCollection()]);

        page_meta()->set('title', __('A wishlist shared with you'));
        breadcrumbs()->add(__('A wishlist shared with you'));

        return view('torgash.wishlist::shared', [
            'products' => $products,
            'paths' => $this->paths($products->getCollection()->pluck('id')->all(), app(CategoryTree::class)),
            'own' => $list->id === app(Wishlists::class)->current()?->id,
        ]);
    }

    /**
     * "Stop these letters", from inside one: a signature names the list and
     * the letter, so nobody has to sign in on the device they read mail on.
     */
    public function unsubscribe(Request $request, WishList $list, string $what)
    {
        $list->forceFill(match ($what) {
            'price' => ['notify_price' => false],
            'stock' => ['notify_stock' => false],
            default => ['notify_price' => false, 'notify_stock' => false],
        })->save();

        return view('torgash.wishlist::unsubscribed', ['what' => $what]);
    }

    /**
     * The category picker: the categories the wished products are in and
     * everything above them, in tree order and indented — never a category
     * that would show nothing.
     *
     * @param  list<int>  $ids
     * @return array<int, string>
     */
    protected function categoryOptions(array $ids, CategoryTree $tree): array
    {
        if ($ids === []) {
            return [];
        }

        $wanted = [];

        foreach (DB::table('m_catalog_category_product')->whereIn('product_id', $ids)->distinct()->pluck('category_id') as $id) {
            $wanted[(int) $id] = true;

            foreach ($tree->ancestors((int) $id) as $ancestor) {
                $wanted[$ancestor] = true;
            }
        }

        // In the visitor's language — CategoryTree::options() speaks the
        // admin's.
        $options = [];

        foreach ($tree->ordered(listedOnly: true) as $category) {
            if (isset($wanted[$category->id])) {
                $options[$category->id] = str_repeat('— ', $tree->depth($category->id)).$category->t('name');
            }
        }

        return $options;
    }

    /**
     * "Запчасти для смартфонов › Тачскрины" under each name: the deepest of
     * the product's categories and its parent.
     *
     * @param  list<int>  $productIds
     * @return array<int, list<array{name: string, slug: string}>>
     */
    protected function paths(array $productIds, CategoryTree $tree): array
    {
        if ($productIds === []) {
            return [];
        }

        $byProduct = [];

        foreach (DB::table('m_catalog_category_product')->whereIn('product_id', $productIds)->get(['product_id', 'category_id']) as $row) {
            $byProduct[(int) $row->product_id][] = (int) $row->category_id;
        }

        $out = [];

        foreach ($byProduct as $productId => $categoryIds) {
            usort($categoryIds, fn ($a, $b) => [$tree->depth($b), $a] <=> [$tree->depth($a), $b]);
            $deepest = $tree->find($categoryIds[0]);

            if (! $deepest || ! $deepest->isListed()) {
                continue;
            }

            $parentId = $tree->ancestors($deepest->id)[0] ?? null;
            $parent = $parentId ? $tree->find($parentId) : null;
            $out[$productId] = array_values(array_filter([
                $parent && $parent->isListed() ? ['name' => $parent->t('name'), 'slug' => $parent->slug] : null,
                ['name' => $deepest->t('name'), 'slug' => $deepest->slug],
            ]));
        }

        return $out;
    }
}
