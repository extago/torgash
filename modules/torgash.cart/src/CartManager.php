<?php

namespace Torgash\Cart;

use Illuminate\Support\Collection;
use Torgash\Cart\Contracts\Basket;
use Torgash\Cart\Models\Cart;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Support\Measurements;

/**
 * A cart: a list of LINES, each `{key, product_id, qty, config}` — see
 * {@see Lines} for why a line is no longer just a product and a number
 * (PLAN §33.3). Amounts are always in base-currency minor units (same unit as
 * Product::price) — format_price() handles display.
 *
 * Stored in a ROW addressed by a token, not in the session (see the carts
 * migration for why). The session holds the token and nothing else, so a
 * browser behaves exactly as it did, while an API client can hold the same
 * token and be talking to the same cart.
 *
 * Implements Basket so that everything which prices a delivery — zones, rate
 * bands, carriers, method conditions — can be pointed at a placed order just as
 * well as at this cart, without a second copy of any of it.
 */
class CartManager implements Basket
{
    /** Where the browser keeps the token. The contents used to live here. */
    protected string $sessionKey = 'cart_token';

    /** The pre-token session key, migrated on first touch and then forgotten. */
    protected string $legacyKey = 'cart';

    protected ?Cart $cart = null;

    /** Set by an API client, which has no session to keep a token in. */
    protected ?string $token = null;

    /**
     * A manager filling a cart on somebody's behalf (an exchange for a
     * return): not held to what a customer may buy, exactly as `PlaceOrder`
     * lets a manager place what a customer could not.
     */
    protected bool $onBehalf = false;

    /**
     * Talk to this cart instead of the session's.
     *
     * The shop API hands a caller a token and takes it back on every
     * subsequent call — that is the whole of §15.1.2, and it is why the cart
     * moved out of the session at all.
     */
    public function useToken(?string $token): static
    {
        $this->token = $token;
        $this->cart = null;

        return $this;
    }

    /** See {@see $onBehalf}. */
    public function onBehalf(): static
    {
        $this->onBehalf = true;

        return $this;
    }

    /**
     * Read the row again. For a caller that filled the basket inside a
     * transaction it then rolled back: the memo would otherwise go on showing
     * lines the database never kept.
     */
    public function reload(): static
    {
        $this->cart = null;

        return $this;
    }

    /** The token of the cart being worked on, minting one if there is none. */
    public function token(): string
    {
        return $this->cart(create: true)->token;
    }

    /**
     * The lines as stored, normalised (PLAN §33.3) — a list of
     * `{key, product_id, qty, config}`, keyed by nothing so that order is the
     * order they were added in.
     *
     * @return list<array{key: string, product_id: int, qty: int, config: array<string, mixed>}>
     */
    public function raw(): array
    {
        // Deliberately does NOT create a cart: `count()` runs on every page for
        // the header badge, and a row per crawler is not a cart, it is a table
        // that grows for ever.
        return Lines::normalise((array) ($this->cart()?->items ?? []));
    }

    /**
     * Put a product in the cart, configured or not, and answer WHICH LINE it
     * went into — the caller needs that to show it or to link to it.
     *
     * The configuration is normalised by whoever understands it
     * (`cart.line.config`) before the key is computed, so "the same server"
     * submitted two different ways is one line of two rather than two lines.
     *
     * @param  array<string, mixed>  $config
     */
    public function add(int $productId, int $qty = 1, array $config = []): string
    {
        $config = $this->normaliseConfig($productId, $config);
        $key = Lines::key($productId, $config);
        $lines = $this->raw();
        $found = false;

        foreach ($lines as $i => $line) {
            if ($line['key'] === $key) {
                $lines[$i]['qty'] = max(1, $line['qty'] + $qty);
                $found = true;

                break;
            }
        }

        if (! $found) {
            $lines[] = ['key' => $key, 'product_id' => $productId, 'qty' => max(1, $qty), 'config' => $config];
        }

        $this->guardQuantity($productId, $lines);
        $this->write($lines);

        return $key;
    }

    /**
     * "This many of this, configured like this" — one verb for add, change and
     * remove, which is what the API answers with (a client should not have to
     * decide which endpoint "how many do you want" is). 0 removes the line.
     *
     * @param  array<string, mixed>  $config
     * @return string the line's key
     */
    public function set(int $productId, int $qty, array $config = []): string
    {
        $config = $this->normaliseConfig($productId, $config);
        $key = Lines::key($productId, $config);

        if ($qty <= 0) {
            $this->remove($key);

            return $key;
        }

        if ($this->line($key)) {
            $this->update($key, $qty);

            return $key;
        }

        $lines = $this->raw();
        $lines[] = ['key' => $key, 'product_id' => $productId, 'qty' => $qty, 'config' => $config];
        $this->guardQuantity($productId, $lines);
        $this->write($lines);

        return $key;
    }

    /**
     * Change a line's quantity. The line is addressed by its KEY, which for a
     * product with nothing configured is its product id — so every caller
     * written before configurations existed still says what it meant.
     */
    public function update(int|string $key, int $qty): void
    {
        $key = (string) $key;
        $lines = [];
        $productId = null;

        foreach ($this->raw() as $line) {
            if ($line['key'] !== $key) {
                $lines[] = $line;

                continue;
            }

            if ($qty > 0) {
                $line['qty'] = $qty;
                $lines[] = $line;
                $productId = $line['product_id'];
            }
        }

        if ($productId !== null) {
            $this->guardQuantity($productId, $lines);
        }

        $this->write($lines);
    }

    /**
     * How many of this line's product may be in this basket — the most the
     * stepper on the cart page can ask for. Null when nothing limits it.
     * Other lines of the same product (another configuration of it) take
     * their share first.
     */
    public function lineLimit(int|string $key): ?int
    {
        $line = $this->line($key);
        $product = $line ? Product::find($line['product_id']) : null;
        $limit = $product?->orderLimit();

        if ($line === null || $limit === null || $this->onBehalf) {
            return null;
        }

        $elsewhere = 0;
        foreach ($this->raw() as $other) {
            if ($other['product_id'] === $line['product_id'] && $other['key'] !== $line['key']) {
                $elsewhere += $other['qty'];
            }
        }

        return max(0, $limit - $elsewhere);
    }

    /**
     * Refuses a basket that would hold more of a product than a customer may
     * buy — a product that cannot be ordered at all, a disabled card, one that
     * does not exist, or more units than are on the shelf (PLAN §31.3). One
     * place for every door that fills a basket: the product page, a list, a
     * kit, one click and the API. `PlaceOrder` asks the same question again,
     * locked, at the moment the goods are actually taken.
     *
     * Only an INCREASE is refused. A basket that already holds more than the
     * shelf (the stock fell while it waited) must still be able to step down
     * one at a time; the cart page says what is wrong with it, and placing
     * the order is refused until it is put right.
     *
     * @param  list<array{key: string, product_id: int, qty: int, config: array<string, mixed>}>  $lines
     */
    protected function guardQuantity(int $productId, array $lines): void
    {
        if ($this->onBehalf) {
            return;
        }

        $count = fn (array $of) => array_sum(array_map(
            fn (array $line) => $line['product_id'] === $productId ? $line['qty'] : 0,
            $of,
        ));
        $wanted = $count($lines);

        if ($wanted <= $count($this->raw())) {
            return;
        }

        $product = Product::find($productId);
        $limit = $product ? $product->orderLimit() : 0;

        if ($limit === null || $wanted <= $limit) {
            return;
        }

        throw \Illuminate\Validation\ValidationException::withMessages(['product' => $limit === 0
            ? __(':product is not available to order right now.', ['product' => $product?->t('name') ?? '#'.$productId])
            : trans_choice(
                'Only :count of :product is left in stock — the basket cannot hold more.|Only :count of :product are left in stock — the basket cannot hold more.',
                $limit,
                ['product' => $product->t('name')],
            )]);
    }

    public function remove(int|string $key): void
    {
        $key = (string) $key;

        $this->write(array_values(array_filter(
            $this->raw(),
            fn (array $line) => $line['key'] !== $key,
        )));
    }

    /**
     * One line, or null — what a configurator needs to reopen the sale the
     * customer is editing ("change this build").
     *
     * @return array{key: string, product_id: int, qty: int, config: array<string, mixed>}|null
     */
    public function line(int|string $key): ?array
    {
        $key = (string) $key;

        foreach ($this->raw() as $line) {
            if ($line['key'] === $key) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Whoever owns the configuration gets to say what it really is — drop what
     * was not asked for, fill a default, refuse an impossible build (by
     * throwing, which the controller turns into the form again). Cart itself
     * understands nothing about it and passes it through untouched.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    protected function normaliseConfig(int $productId, array $config): array
    {
        $product = Product::find($productId);

        // A product closed to this visitor's group (PLAN §46) is not sold to
        // them by any door — the page, a list, the API.
        if ($product && ! app(\Torgash\Catalog\Support\Assortment::class)->productOpen($product)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['product' => __('This product is not available to your account.')]);
        }

        $config = hook_filter('cart.line.config', $config, [
            'product_id' => $productId,
            'product' => $product,
            // Which basket this is being configured into. A module that stores
            // something of its own beside the line (a file a customer sent)
            // needs to know whose basket it belongs to, so that an abandoned
            // one can be tidied up — and the same seam is used by the ADMIN
            // editing a placed order, where there is no basket and this is
            // absent.
            'cart_token' => $this->token(),
        ]);

        return Lines::canonical(is_array($config) ? $config : []);
    }

    /**
     * Emptied, not deleted: the row is what the token points at, and an order
     * placed a second ago is the most interesting cart the shop has. The sweep
     * takes it away later.
     */
    public function clear(): void
    {
        if ($cart = $this->cart()) {
            $cart->store([]);
            $cart->forceFill(['checkout' => null])->save();
            $this->setCouponCode(null);
        }
    }

    /**
     * Cart knows only that a coupon CODE string is attached to it — it has no
     * concept of what a coupon is, no dependency on torgash.checkout.
     * Validation/discount calculation is entirely checkout's job, wired in via
     * hook_filter('cart.rows_before_total'/'cart.total'), same pattern as the
     * catalog.product.buy_box hook.
     */
    public function couponCode(): ?string
    {
        return $this->cart()?->coupon_code;
    }

    public function setCouponCode(?string $code): void
    {
        $cart = $this->cart(create: (bool) $code);

        $cart?->forceFill(['coupon_code' => $code ?: null, 'last_active_at' => now()])->save();
    }

    /**
     * The checkout answers given so far — who, where, by what delivery, paid
     * how. Empty for a browser, which asks them all at once on one page.
     *
     * @return array<string, mixed>
     */
    public function checkout(): array
    {
        return (array) ($this->cart()?->checkout ?? []);
    }

    /**
     * MERGED, never replaced: each step of an API checkout answers its own
     * question and must not silently unanswer the ones before it. A field sent
     * as null is an erasure and is honoured as one.
     *
     * @param  array<string, mixed>  $answers
     */
    public function setCheckout(array $answers): void
    {
        $cart = $this->cart(create: true);

        $cart->forceFill([
            'checkout' => array_filter(
                array_merge($this->checkout(), $answers),
                fn ($value) => $value !== null,
            ),
            'last_active_at' => now(),
        ])->save();
    }

    /**
     * The cart this request is about.
     *
     * Memoised on the manager, which is a per-request singleton: `count()`,
     * `items()` and `total()` are all asked several times while one page
     * renders, and each would otherwise be its own query.
     *
     * `create` is false everywhere a cart is only READ, so looking at a shop
     * never writes a row.
     */
    protected function cart(bool $create = false): ?Cart
    {
        if ($this->cart) {
            return $this->cart;
        }

        $token = $this->token ?: session($this->sessionKey);

        if ($token) {
            $this->cart = Cart::where('token', $token)->first();
        }

        // A cart built before this table existed lives in the old session key.
        // Moved once, on first touch, so nobody loses what they had picked.
        $legacy = (array) session($this->legacyKey, []);

        if (! $this->cart && ($create || $legacy !== [])) {
            $this->cart = Cart::create([
                'token' => Cart::newToken(),
                'user_id' => auth()->id(),
                'storefront_id' => current_storefront()?->id,
                'items' => Lines::normalise($legacy),
                'coupon_code' => session('cart_coupon'),
                'last_active_at' => now(),
            ]);

            // Only a browser has a session to remember it in; an API caller is
            // handed the token in the response instead, and is recognised here
            // by having named one.
            if (! $this->token) {
                session([$this->sessionKey => $this->cart->token]);
                session()->forget([$this->legacyKey, 'cart_coupon']);
            }
        }

        // Stamped whenever a signed-in visitor touches it, which is what will
        // let a cart follow somebody to another device.
        if ($this->cart && auth()->check() && $this->cart->user_id !== auth()->id()) {
            $this->cart->forceFill(['user_id' => auth()->id()])->save();
        }

        return $this->cart;
    }

    /** @param list<array<string, mixed>> $items */
    protected function write(array $items): void
    {
        $this->cart(create: true)->store(array_values($items));
    }

    /** @return Collection<int, object{key: string, product: Product, qty: int, config: array<string, mixed>, parts: list<array<string, mixed>>, unit_price: int, extra_total: int, line_total: int, problem: ?string}> */
    public function items(): Collection
    {
        $cart = $this->raw();

        if (empty($cart)) {
            return collect();
        }

        // withCardData(), not with('priceTiers'): a cart line renders a 120px
        // thumbnail too, and without the media relation that was one extra
        // query per line (plus priceTypeOverrides for effectivePrice below).
        $products = Product::whereIn('id', array_column($cart, 'product_id'))->withCardData()->get()->keyBy('id');

        // What the basket holds of each product across its lines (two builds of
        // one server are one shelf) — measured against what may be bought.
        $held = [];
        foreach ($cart as $line) {
            $held[$line['product_id']] = ($held[$line['product_id']] ?? 0) + $line['qty'];
        }

        return collect($cart)
            ->map(function (array $line) use ($products, $held) {
                $product = $products->get($line['product_id']);

                if (! $product) {
                    return null;
                }

                $limit = $this->onBehalf ? null : $product->orderLimit();

                // effectivePrice() defaults to the current visitor — this is
                // always "my cart", never someone else's — and now takes qty
                // so a wholesale/quantity-break tier applies as soon as the
                // cart line crosses its threshold.
                $basePrice = $product->effectivePrice(qty: $line['qty']);
                // What the configuration adds: named, priced, sometimes a
                // product of its own. Nothing answers in a shop with no
                // options module, and then a line is what it always was.
                $parts = Lines::parts($product, $line['qty'], $line['config']);
                $money = Lines::money($basePrice, $parts, $line['qty']);

                return (object) [
                    'key' => $line['key'],
                    'product' => $product,
                    'qty' => $line['qty'],
                    'config' => $line['config'],
                    'parts' => $parts,
                    'base_price' => $basePrice,
                    'unit_price' => $money['unit_price'],
                    'extra_total' => $money['extra_total'],
                    'line_total' => $money['line_total'],
                    // Why this line cannot be ordered as it stands — the stock
                    // fell, or the product was closed, while it waited here.
                    // Null for a line that can. Placing is refused until it is
                    // put right, so the cart page says so first.
                    'problem' => match (true) {
                        $limit === 0 => __('Not available to order right now — remove it to place the order.'),
                        $limit !== null && $held[$line['product_id']] > $limit => __('Only :count left in stock — lower the quantity to place the order.', ['count' => $limit]),
                        default => null,
                    },
                ];
            })
            ->filter()
            ->values();
    }

    /** Whether any line cannot be ordered as it stands ({@see items()}'s `problem`). */
    public function hasProblems(): bool
    {
        return $this->items()->contains(fn ($item) => $item->problem !== null);
    }

    public function needsDelivery(): bool
    {
        $items = $this->items();

        return $items->isEmpty() || $items->contains(fn ($item) => $item->product->needsDelivery());
    }

    public function count(): int
    {
        return array_sum(array_column($this->raw(), 'qty'));
    }

    public function total(): int
    {
        return $this->items()->sum('line_total');
    }

    /**
     * Total weight in grams — what a per-kilogram shipping rate charges on.
     *
     * Asked of the catalogue rather than read off the column: a shop whose
     * weights arrived as a characteristic still gets a real number (see
     * Torgash\Catalog\Support\Measurements). Lines nobody has measured count as
     * nothing here — weightIsKnown() is the question to ask before charging by
     * weight at all.
     */
    public function weight(): int
    {
        $items = $this->items();
        // One query for the whole cart, not one per line.
        app(Measurements::class)->prime($items->map(fn ($item) => $item->product));

        return $items->sum(fn ($item) => ($item->product->resolvedWeight() ?? 0) * $item->qty
            // A configured server weighs its processors too — whoever priced
            // the part is also who knows what it weighs.
            + Lines::partsWeight($item->parts, $item->qty));
    }

    /**
     * True when every line has a weight. False is what makes a shipping method
     * say "the carrier will quote it" instead of quietly pricing a parcel that
     * weighs nothing (PLAN §12.4).
     */
    public function weightIsKnown(): bool
    {
        $items = $this->items();

        if ($items->isEmpty()) {
            return true;
        }

        app(Measurements::class)->prime($items->map(fn ($item) => $item->product));

        return $items->every(fn ($item) => $item->product->resolvedWeight() !== null);
    }

    /**
     * The goods' own volume in cubic centimetres — what volumetric weight is
     * computed from.
     *
     * Deliberately the sum of the products, not a box: which carton a shop
     * would actually use is a packing problem this engine does not pretend to
     * solve. A method that charges on volume applies its own packing factor to
     * this (see ShippingMethod::billableWeight), and the carrier remeasures the
     * real parcel anyway.
     */
    public function volume(): int
    {
        $items = $this->items();

        return (int) round($items->sum(function ($item) {
            $box = $item->product->resolvedBox();

            // Millimetres cubed to centimetres cubed: 10 × 10 × 10.
            return $box === null
                ? 0
                : $box['length'] * $box['width'] * $box['height'] / 1000 * $item->qty;
        }));
    }

    /** True when every line has all three sides measured. */
    public function volumeIsKnown(): bool
    {
        $items = $this->items();

        return $items->isEmpty()
            || $items->every(fn ($item) => $item->product->resolvedBox() !== null);
    }
}
