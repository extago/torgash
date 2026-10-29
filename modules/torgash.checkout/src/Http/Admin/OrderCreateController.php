<?php

namespace Torgash\Checkout\Http\Admin;

use App\Http\Controllers\Controller;
use App\Models\Storefront;
use App\Models\User;
use Illuminate\Http\Request;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Orders\ManagerOrder;
use Torgash\Checkout\Orders\PlaceOrder;

/**
 * A new order placed by a manager (PLAN §63.8): one page that asks what the
 * checkout asks — who, what, where to, how paid — and three small answers
 * it fetches on the way: the customers a typed name matches, the products
 * a typed word matches, and the deliveries the shop would offer this basket
 * to that address, priced now. The order itself is made by ManagerOrder.
 */
class OrderCreateController extends Controller
{
    public function __construct(protected ManagerOrder $orders, protected PlaceOrder $placing) {}

    public function create()
    {
        return view('torgash.checkout::admin.orders.create', [
            // Paid in the shop's own currency (ManagerOrder::rules says the same).
            'paymentMethods' => $this->placing->paymentMethods(shop_currency()),
            'countries' => (array) hook_filter('checkout.location.countries', []),
            'defaultCountry' => \App\Core\Support\Phones::defaultCountry(),
            // The town as a type-ahead over the registry, with its place id —
            // what zones and carriers match on — where the shipping module
            // brings one; a plain box otherwise.
            'townField' => view()->exists('torgash.shipping::partials.town-field'),
            'takesCompanies' => $this->placing->takesCompanies(),
            'postalMode' => (string) setting('torgash.checkout', 'postal_code', 'optional'),
            'storefronts' => Storefront::query()->where('enabled', true)->orderByDesc('is_default')->orderBy('id')->get(),
        ]);
    }

    /** The accounts a typed name, email or phone matches — a handful, newest first. */
    public function customers(Request $request)
    {
        // Sent by the box under its own name (htmx), or as `q` by a client.
        $needle = trim((string) ($request->get('customer_q') ?? $request->get('q', '')));
        $digits = preg_replace('/\D+/', '', $needle);

        $customers = $needle === '' ? collect() : User::query()->customers()
            ->with(['addresses' => fn ($q) => $q->orderByDesc('is_default')->orderByDesc('id')])
            ->where(fn ($w) => $w
                ->where('name', 'ilike', '%'.$needle.'%')
                ->orWhere('email', 'ilike', '%'.$needle.'%')
                ->when(strlen($digits) >= 4, fn ($w) => $w->orWhere('phone', 'like', '%'.$digits.'%'))
                ->orWhereHas('addresses', fn ($a) => $a->where('phone', 'ilike', '%'.$needle.'%')))
            ->orderByDesc('id')
            ->limit(8)
            ->get();

        return view('torgash.checkout::admin.orders._create_customers', [
            'customers' => $customers,
            'needle' => $needle,
        ]);
    }

    /** The products a typed word matches, in any language, by name or SKU. */
    public function products(Request $request)
    {
        $needle = trim((string) ($request->get('product_q') ?? $request->get('q', '')));

        $products = $needle === '' ? collect() : Product::query()
            ->withCardData()
            ->where(fn ($q) => $q
                ->where('sku', 'ilike', '%'.$needle.'%')
                ->orWhereRaw('exists (select 1 from jsonb_each_text(name) as t(k, v) where t.v ilike ?)', ['%'.$needle.'%']))
            ->orderBy('id')
            ->limit(10)
            ->get();

        return view('torgash.checkout::admin.orders._create_products', [
            'products' => $products,
            'needle' => $needle,
        ]);
    }

    /**
     * The deliveries for the basket as it stands and the address as typed,
     * fetched whenever either changes. With no lines yet there is nothing to
     * price, and the fragment says so.
     */
    public function delivery(Request $request)
    {
        $lines = collect((array) $request->input('lines', []))
            ->filter(fn ($line) => is_array($line) && (int) ($line['product_id'] ?? 0) > 0)
            ->map(fn ($line) => ['product_id' => (int) $line['product_id'], 'qty' => max(1, (int) ($line['qty'] ?? 1))])
            ->values()
            ->all();

        if ($lines === []) {
            return view('torgash.checkout::admin.orders._create_delivery', ['quote' => null, 'selected' => null, 'needsDelivery' => true]);
        }

        $cart = $this->orders->cartFor($lines, $this->storefront($request)->id);

        try {
            $selected = $request->input('shipping_method_id');
            $quote = $this->orders->quote($cart, $this->orders->location($request), $selected);

            return view('torgash.checkout::admin.orders._create_delivery', [
                'quote' => $quote,
                'selected' => $selected !== null && $quote->option($selected) ? (string) $selected : (string) ($quote->options->keys()->first() ?? ''),
                'needsDelivery' => $cart->needsDelivery(),
                'shippingActive' => $this->placing->shippingIsActive(),
            ]);
        } finally {
            $this->orders->forget($cart);
        }
    }

    public function store(Request $request)
    {
        $lines = $this->orders->lines($request);
        $storefront = $this->storefront($request);
        $customer = $request->filled('user_id') ? User::query()->customers()->find((int) $request->input('user_id')) : null;
        $cart = $this->orders->cartFor($lines, $storefront->id);

        try {
            $location = $this->orders->location($request);
            $selected = $request->input('shipping_method_id');
            $quote = $this->orders->quote($cart, $location, $selected);
            $validated = $request->validate($this->orders->rules($quote, $selected, $cart));

            $order = $this->orders->place($validated, $cart, $location, $quote, $customer, $storefront, $lines, $request->user());
        } finally {
            $this->orders->forget($cart);
        }

        return redirect()->route('admin.orders.show', $order)
            ->with('status', __('Order :number created.', ['number' => $order->order_number]));
    }

    /** The storefront the order is for: the one chosen, else the shop's default. */
    protected function storefront(Request $request): Storefront
    {
        $chosen = $request->filled('storefront_id')
            ? Storefront::query()->where('enabled', true)->find((int) $request->input('storefront_id'))
            : null;

        return $chosen
            ?? Storefront::query()->where('enabled', true)->where('is_default', true)->first()
            ?? Storefront::query()->where('enabled', true)->orderBy('id')->firstOrFail();
    }
}
