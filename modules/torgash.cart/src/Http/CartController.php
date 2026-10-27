<?php

namespace Torgash\Cart\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Torgash\Cart\CartManager;
use Torgash\Cart\ListBuy;
use Torgash\Catalog\Models\Product;

class CartController extends Controller
{
    public function index(CartManager $cart)
    {
        return view('torgash.cart::index', ['cart' => $cart]);
    }

    /**
     * Returns the badge fragment — called from the product page buy box.
     *
     * `config` is the line's configuration BAG — a key per module that
     * configures this product, and cart itself makes nothing of any of it
     * (PLAN §33.3). The module that does may refuse what was submitted, which
     * comes back as an ordinary validation error on the form.
     */
    public function add(Request $request, CartManager $cart, Product $product)
    {
        try {
            $key = $cart->add($product->id, max(1, (int) $request->input('qty', 1)), (array) $request->input('config', []));
        } catch (ValidationException $e) {
            // Refused from a LIST — a card's button, the search window — where
            // there is no buy box to answer into: the badge stays as it was and
            // the reason goes to a corner notice.
            if ($request->header('HX-Trigger') !== 'buy-box-'.$product->id.'-form') {
                return response()->view('torgash.cart::partials.refused', [
                    'product' => $product,
                    'message' => (string) collect($e->errors())->flatten()->first(),
                ]);
            }

            // The form is on the product page and was posted by HTMX, which
            // does not swap a 4xx at all — so the answer is the buy box again,
            // with what is wrong in it, and a 200 (the rule the guarded public
            // forms already live by).
            //
            // RETARGETED, because the form swaps nothing of its own (its
            // usual answer is all out of band, see SHOWS): a refusal is a
            // different answer and says where it goes. (When the form still
            // aimed at the header badge, a refusal without these headers
            // replaced the little cart icon with an entire buy panel —
            // measured.)
            return response()
                ->view('torgash.cart::partials.buy-box', [
                    'product' => $product,
                    'configErrors' => $e->errors(),
                    'config' => (array) $request->input('config', []),
                ])
                ->withHeaders([
                    'HX-Retarget' => '#buy-box-'.$product->id,
                    'HX-Reswap' => 'outerHTML',
                ]);
        }

        app(ListBuy::class)->forgetCart();
        $mode = self::afterAdd($request);
        // Priced by the basket itself (the configuration, a quantity break),
        // and only when somebody is going to be shown it.
        $items = $mode === 'nothing' ? collect() : $cart->items();

        return response()->view('torgash.cart::partials.added', [
            'product' => $product,
            'qty' => max(1, (int) $request->input('qty', 1)),
            'line' => $items->firstWhere('key', $key),
            'mode' => $mode,
            'basketCount' => $cart->count(),
            'basketTotal' => (int) $items->sum('line_total'),
        ]);
    }

    /**
     * What the customer is shown once something is in the basket. The shop's
     * setting, unless the form asked for another answer — the search window
     * has its own (its button already turns into "In cart"). Only the three
     * known words are accepted from a form.
     */
    public static function afterAdd(Request $request): string
    {
        $asked = (string) $request->input('after_add', '');
        $modes = ['dialog', 'toast', 'nothing'];

        if (in_array($asked, $modes, true)) {
            return $asked;
        }

        $setting = (string) setting('torgash.cart', 'after_add');

        return in_array($setting, $modes, true) ? $setting : 'dialog';
    }

    /**
     * The choice window: a product that must be chosen before it is bought —
     * one of several variations, or one that asks something — opened from a
     * list instead of dropping a guess into the basket. Its buy box is the
     * product page's own (the same fields, the same refusals, answered into
     * the same box), so a choice made here cannot differ from one made there.
     * A variation switches the window to its sibling.
     */
    public function choose(Product $product)
    {
        abort_unless(Product::reachable()->whereKey($product->id)->exists(), 404);

        $variations = app(\Torgash\Catalog\Support\Variations::class)->for($product)['axes'];

        return view('torgash.cart::partials.choose', [
            'product' => $product,
            'variations' => $variations,
        ]);
    }

    /** Quick view: the choice window, wider — the pictures, the short list, the same buy box. */
    public function quick(Product $product)
    {
        abort_unless(setting('torgash.cart', 'quick_view', true) && Product::reachable()->whereKey($product->id)->exists(), 404);

        $product->loadMissing('featureAssignments.feature', 'featureAssignments.featureValue');

        return view('torgash.cart::partials.quick', [
            'product' => $product,
            'variations' => app(\Torgash\Catalog\Support\Variations::class)->for($product)['axes'],
            'specs' => $product->featureAssignments
                ->filter(fn ($pf) => $pf->feature && $pf->display() !== null && $pf->feature->shows('buy_box'))
                ->take(max(0, (int) setting('torgash.catalog', 'buy_box_specs_max', 4))),
        ]);
    }

    /**
     * A line's quantity from the cart page: typed (`qty`) or stepped by the
     * − / + buttons (`step`, added to the quantity the page showed). Two names
     * rather than two `qty` fields, because a form that sends one value twice
     * leaves the server holding whichever came last. Never below one here —
     * taking a line away is the bin's job, not a slip of the minus.
     */
    public function updateItem(Request $request, CartManager $cart, string $line)
    {
        $qty = max(1, (int) $request->input('qty', 1) + (int) $request->input('step', 0));

        // Never past what may be bought: a number typed beyond the shelf
        // becomes the shelf, and the line itself says why if it still cannot
        // be ordered as it stands.
        if (($limit = $cart->lineLimit($line)) !== null) {
            $qty = min($qty, max(1, $limit));
        }

        try {
            $cart->update($line, $qty);
        } catch (ValidationException) {
            // The shelf moved between the two reads; the line explains it.
        }

        return $this->answer($request, $cart);
    }

    public function removeItem(Request $request, CartManager $cart, string $line)
    {
        $cart->remove($line);

        return $this->answer($request, $cart);
    }

    /** "Clear the cart": everything out at once — the lines, the coupon, the checkout answers. */
    public function clear(Request $request, CartManager $cart)
    {
        $cart->clear();

        return $this->answer($request, $cart);
    }

    /** What a page can show of the basket, as cart.js reports it (SHOWS). */
    public const PARTS = ['badge', 'tabbar', 'contents'];

    /**
     * The request header in which cart.js lists which of PARTS the page that
     * sent this request actually has.
     *
     * An add used to AIM at the header badge (`hx-target="#cart-badge"`), and
     * HTMX does not send a request whose target is missing. The cart and
     * checkout draw a quiet header with no badge on purpose (a way out of
     * paying), so every add on the cart page — "You may also like" right under
     * the basket — silently did nothing. Now an add aims at nothing
     * (`hx-swap="none"`) and the answer brings, out of band, exactly what this
     * page shows: the badge where there is one, and the basket itself on the
     * basket page, which was the one place an add could not be seen at all.
     * Out of band only for what EXISTS, because HTMX reports a fragment with no
     * place to go as an error.
     */
    public const SHOWS = 'X-Torgash-Cart-Shows';

    /**
     * Which of PARTS the requesting page has. A request without the header (no
     * cart.js — a test, an old cached page) gets what every page used to get:
     * the badge and the tab bar's count.
     *
     * @return list<string>
     */
    public static function shows(Request $request): array
    {
        if (! $request->headers->has(self::SHOWS)) {
            return ['badge', 'tabbar'];
        }

        return array_values(array_intersect(
            self::PARTS,
            array_map('trim', explode(',', (string) $request->header(self::SHOWS))),
        ));
    }

    /**
     * HTMX gets the contents to swap in; a browser with no script posted a
     * real form and goes back to the page it was on.
     */
    protected function answer(Request $request, CartManager $cart)
    {
        return $request->header('HX-Request')
            ? view('torgash.cart::partials.contents', ['cart' => $cart])
            : redirect()->route('cart.index');
    }
}
