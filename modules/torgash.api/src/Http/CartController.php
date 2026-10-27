<?php

namespace Torgash\Api\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Torgash\Cart\CartManager;
use Torgash\Cart\Models\Cart;
use Torgash\Catalog\Models\Product;

/**
 * A cart over HTTP: created empty, then addressed by its token.
 *
 * The token is the authorisation — there is nothing else to check, and nothing
 * else to leak. A caller who has it may read and change that cart and no other;
 * a caller who does not cannot even tell whether it exists (an unknown token is
 * a 404, not "wrong token").
 *
 * Every operation goes through CartManager, the same object the storefront's
 * own pages use, so quantity clamping, per-quantity pricing, weights and the
 * coupon behave identically over HTTP and in a browser. An API that reimplements
 * "add to cart" is an API that prices things differently from the shop.
 */
class CartController extends Controller
{
    public function store(Request $request)
    {
        $cart = app(CartManager::class)->useToken(null);

        // Created empty and immediately: the token is what the client stores,
        // so it has to exist before there is anything in it.
        $token = Cart::create([
            'token' => Cart::newToken(),
            'storefront_id' => current_storefront()?->id,
            'items' => [],
            'last_active_at' => now(),
        ])->token;

        return response()->json(['data' => $this->body($cart->useToken($token))], 201);
    }

    public function show(Request $request, string $token)
    {
        return response()->json(['data' => $this->body($this->cart($token))]);
    }

    /**
     * Set the quantity of one product. Zero removes it — one verb for all three
     * of add, change and remove, because "how many of this do you want" is one
     * question and a client should not have to decide which endpoint it is.
     */
    public function items(Request $request, string $token)
    {
        $validated = $request->validate([
            'product' => 'required_without:line',
            'line' => 'nullable|string|max:64',
            'quantity' => 'required|integer|min:0|max:9999',
            // The line's configuration bag (PLAN §33.3) — a key per module
            // that configures this product, `options` being the configurator's
            // own. A product with nothing to configure ignores it, and the
            // line is then addressed by the product id exactly as it was.
            'config' => 'nullable|array',
        ]);

        $cart = $this->cart($token);

        // A LINE, when the caller names one: one product can be in the cart
        // twice, configured two ways, and only the line key tells them apart.
        // Its own configuration is kept — changing how many you want is not
        // changing what you are buying.
        if (! empty($validated['line'])) {
            abort_unless($cart->line($validated['line']), 404, __('No such cart line.'));

            $cart->update($validated['line'], (int) $validated['quantity']);

            return response()->json(['data' => $this->body($cart)]);
        }

        // Addressed the same way everything else is: our id, the sku, or the
        // id the caller's own system knows it by. `reachable()` and not
        // `listed()`: a hidden product is still buyable by direct link, which
        // is the whole point of the hidden state — the same rule the product
        // page follows.
        $product = Product::reachable()
            ->where(fn ($q) => $q
                ->where('id', is_numeric($validated['product']) ? $validated['product'] : 0)
                ->orWhere('sku', $validated['product'])
                ->orWhere('external_id', $validated['product']))
            ->first();

        abort_unless($product, 404, __('No such product.'));

        $cart->set($product->id, (int) $validated['quantity'], (array) ($validated['config'] ?? []));

        return response()->json(['data' => $this->body($cart)]);
    }

    public function clear(Request $request, string $token)
    {
        $cart = $this->cart($token);
        $cart->clear();

        return response()->json(['data' => $this->body($cart)]);
    }

    /**
     * An unknown token is a 404 and not a 403: telling a stranger that a cart
     * exists but is not theirs is telling them something.
     */
    protected function cart(string $token): CartManager
    {
        abort_unless(Cart::where('token', $token)->exists(), 404, __('No such cart.'));

        return app(CartManager::class)->useToken($token);
    }

    /** @return array<string, mixed> */
    protected function body(CartManager $cart): array
    {
        return [
            'token' => $cart->token(),
            'currency' => current_currency(),
            'items' => $cart->items()->map(fn ($item) => [
                // What addresses this line from now on — the product id for a
                // plain product, something else for a configured one.
                'line' => $item->key,
                'product' => [
                    'id' => $item->product->id,
                    'sku' => $item->product->sku,
                    'external_id' => $item->product->external_id,
                    'name' => $item->product->t('name'),
                    'url' => url('/api/v2/shop/products/'.$item->product->id),
                ],
                'quantity' => $item->qty,
                // Minor units of the shop's books, like every other amount on
                // the wire. The visitor's own currency is a display question,
                // and `currency` above says which one that is.
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
                // What is inside this line and already in its price: the
                // processors, the licences, the extended warranty. Absent for
                // a plain product rather than an empty list, so a client
                // written before configurations reads the same shape.
                ...($item->parts === [] ? [] : ['options' => array_map(fn (array $part) => [
                    'label' => $part['label'],
                    'quantity' => $part['qty'],
                    'per_item' => $part['per_unit'],
                    'amount' => $part['amount'],
                    'product_id' => $part['product_id'],
                ], $item->parts)]),
                // Why this line cannot be ordered as it stands — the stock fell
                // while it waited. Absent for a line that can, so the shape a
                // client already reads does not change.
                ...($item->problem === null ? [] : ['problem' => $item->problem]),
            ])->values(),
            'count' => $cart->count(),
            'total' => $cart->total(),
            'coupon' => $cart->couponCode(),
        ];
    }
}
