<?php

namespace Torgash\Taxes;

use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;

/**
 * Everything this module does to the rest of the shop goes through hooks, so
 * uninstalling it leaves prices, carts and orders exactly as they were.
 */
class TaxesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Singleton, not statics: see the class docblock.
        $this->app->singleton(TaxResolver::class);
        $this->app->singleton(TaxCalculator::class);

        // Remembers where THIS visitor said they are, so it is forgotten with
        // the request — and the calculator, which holds it, with it.
        $this->app->scoped(Destinations::class);
        PerRequest::forget($this->app, Destinations::class, TaxCalculator::class);
    }

    /**
     * The resolver is resolved inside each listener rather than handed to them
     * once here. A hook listener registered at boot outlives the request under
     * a persistent worker, so a closure holding this request's TaxResolver
     * would keep answering from its memo for the next visitor — the same trap
     * as a `static`, which the class docblock already warns about.
     */
    public function boot(): void
    {
        $this->productForm();
        $this->prices();
        $this->totals();

        // A price with tax and the "incl. VAT" under it are worked out for
        // the visitor's town: every page kept ready for guests is filed
        // under the rates that apply there (PLAN §61, Destinations).
        page_cache()
            ->vary('tax_place', fn () => app(Destinations::class)->signature())
            ->unseen('m_taxes_order_taxes');

        // What must be true about charging tax, for whoever is watching. Never
        // fired when `torgash.guard` is absent — a statement about our own
        // data, not a dependency.
        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\TaxInvariants)->register());

        Route::middleware(['web', 'auth', 'can:taxes.manage'])
            ->prefix('admin/taxes')
            ->name('admin.taxes.')
            ->group(dirname(__DIR__).'/routes-admin.php');
    }

    /** The per-product tax choice in the admin product form, and saving it. */
    protected function productForm(): void
    {
        hook_listen('catalog.product.pricing_extra', function (string $value, array $context) {
            if (! app(TaxResolver::class)->active()) {
                return $value;
            }

            /** @var Product $product */
            $product = $context['product'];

            return $value.view('torgash.taxes::partials.product-field', [
                'taxes' => app(TaxResolver::class)->all(),
                'selected' => $product->exists
                    ? DB::table('m_taxes_product_tax')->where('product_id', $product->id)->pluck('tax_id')->all()
                    : [],
                'hasOverride' => $product->exists
                    && DB::table('m_taxes_product_tax')->where('product_id', $product->id)->exists(),
                'defaults' => app(TaxResolver::class)->defaults(),
            ])->render();
        });

        hook_listen('catalog.product.saved', function (array $context) {
            $product = $context['product'];
            $request = $context['request'];

            // Absent field (another form, an API call) must not wipe the
            // product's taxes — only an explicit "custom" choice writes rows.
            if (! $request->has('taxes_mode')) {
                return;
            }

            DB::table('m_taxes_product_tax')->where('product_id', $product->id)->delete();

            if ($request->input('taxes_mode') !== 'custom') {
                return;
            }

            foreach ((array) $request->input('taxes', []) as $taxId) {
                DB::table('m_taxes_product_tax')->insert([
                    'product_id' => $product->id,
                    'tax_id' => (int) $taxId,
                ]);
            }
        });
    }

    /** What the storefront shows next to a product. */
    protected function prices(): void
    {
        hook_listen('catalog.products.listed', function (array $context) {
            app(TaxResolver::class)->prime(collect($context['products'] ?? [])->pluck('id')->filter());
        });

        // Only ADDED taxes change a displayed price, and only when the shop
        // asked for it. An included tax is already in the number.
        hook_listen('catalog.price.display', function (int $price, array $context) {
            if (! setting('torgash.taxes', 'display_with_tax') || ! app(TaxResolver::class)->active()) {
                return $price;
            }

            $product = $context['product'] ?? null;

            return $product?->id
                ? app(TaxCalculator::class)->priceWithTax($price, $this->shownTaxes($product->id))
                : $price;
        });

        // "incl. VAT 20%" under the price on the product page — the line a CIS
        // shop is legally expected to show, and the one a US shop never has.
        hook_listen('catalog.product.price_extra', function (string $value, array $context) {
            $product = $context['product'] ?? null;

            if (! $product?->id || ! app(TaxResolver::class)->active()) {
                return $value;
            }

            $applied = $this->shownTaxes($product->id);

            if ($applied === []) {
                return $value;
            }

            return $value.view('torgash.taxes::partials.price-note', ['taxes' => $applied])->render();
        });
    }

    /** Cart, checkout and the order snapshot. */
    protected function totals(): void
    {
        hook_listen('cart.rows_before_total', function (string $value, array $context) {
            if (! app(TaxResolver::class)->active()) {
                return $value;
            }

            $lines = app(TaxCalculator::class)->forCart(
                $this->cartLines($context['cart']),
                where: app(Destinations::class)->visitor(),
            );

            return $lines === []
                ? $value
                : $value.view('torgash.taxes::partials.cart-rows', ['lines' => $lines])->render();
        });

        hook_listen('cart.total', function (int $total, array $context) {
            if (! app(TaxResolver::class)->active()) {
                return $total;
            }

            $charged = app(TaxCalculator::class)->forCart(
                $this->cartLines($context['cart']),
                where: app(Destinations::class)->visitor(),
            );

            foreach ($charged as $line) {
                if (! $line->included()) {
                    $total += $line->amount;
                }
            }

            return $total;
        });

        hook_listen('checkout.extra_totals', function (array $lines, array $context) {
            if (! app(TaxResolver::class)->active()) {
                return $lines;
            }

            $charged = app(TaxCalculator::class)->forCart(
                $this->cartLines($context['cart']),
                $context['discount'] ?? 0,
                $context['shipping_cost'] ?? 0,
                // The address being checked out to — checkout hands over the
                // one it quoted delivery for, so the tax and the delivery are
                // worked out for the same place.
                $context['location'] ?? app(Destinations::class)->visitor(),
            );

            foreach ($charged as $line) {
                $lines[] = [
                    'code' => 'tax:'.$line->tax->code,
                    'label' => $line->included()
                        ? __('incl. :tax', ['tax' => $line->label()])
                        : $line->label(),
                    'amount' => $line->amount,
                    'included' => $line->included(),
                ];
            }

            return $lines;
        });

        // Written from the ORDER's own lines, not from the cart: by the time
        // this runs the cart may already be gone, and an order has to be able
        // to explain itself years later from what it stored.
        hook_listen('checkout.order_placed', function (array $context) {
            $this->snapshot($context['order']);
        });

        // An admin edited the order's contents. The frozen rows now describe
        // lines that are no longer there, so they are replaced from what the
        // order says NOW — otherwise the totals stop adding up.
        //
        // This is the one thing that DOES rewrite an order's tax snapshot, and
        // it is a deliberate exception to "changing a rate never rewrites a
        // placed order": the rate did not change, the order did. The cost of
        // the exception is that an order edited after a rate change is
        // recalculated at the new rate — which is why the edit is logged.
        hook_listen('checkout.order.recalculated', function (array $context) {
            /** @var Order $order */
            $order = $context['order'];

            DB::table('m_taxes_order_taxes')->where('order_id', $order->id)->delete();

            $this->snapshot($order);
        });

        // A page of orders reads its snapshots in one query (OrderRows).
        hook_listen('checkout.orders.listed', fn (array $context) => app(\Torgash\Checkout\Support\OrderRows::class)
            ->prime('m_taxes_order_taxes', $context['orders']->pluck('id')->all()));

        hook_listen('checkout.order.extra_totals', function (array $lines, array $context) {
            /** @var Order $order */
            $order = $context['order'];

            $rows = app(\Torgash\Checkout\Support\OrderRows::class)->for('m_taxes_order_taxes', (int) $order->id);

            foreach ($rows as $row) {
                $lines[] = [
                    'code' => 'tax:'.$row->id,
                    'label' => $row->price_includes_tax
                        ? __('incl. :tax', ['tax' => $row->name])
                        : $row->name,
                    'amount' => (int) $row->amount,
                    'included' => (bool) $row->price_includes_tax,
                ];
            }

            return $lines;
        });
    }

    /**
     * A product's taxes as the visitor of this page will be charged them —
     * what the price and the "incl. VAT" note under it are about.
     *
     * @return list<\Torgash\Taxes\Models\Tax>
     */
    protected function shownTaxes(int $productId): array
    {
        return app(Destinations::class)->apply(
            app(TaxResolver::class)->forProduct($productId),
            app(Destinations::class)->visitor(),
        );
    }

    /** @return list<array{product_id: int, unit_price: int, qty: int}> */
    protected function cartLines($cart): array
    {
        return $cart->items()->map(fn ($item) => [
            'product_id' => $item->product->id,
            'unit_price' => $item->unit_price,
            'qty' => $item->qty,
        ])->values()->all();
    }

    /**
     * Freeze what this order owes onto itself, computed from the ORDER's own
     * lines and not from a cart: by the time this runs the cart may be gone,
     * and an order has to be able to explain itself years later from what it
     * stored. Writes nothing when the shop charges no tax — an order with no
     * rows simply contributes no tax lines to its own totals.
     */
    protected function snapshot(Order $order): void
    {
        if (! app(TaxResolver::class)->active()) {
            return;
        }

        $lines = app(TaxCalculator::class)->forCart(
            $this->orderLines($order),
            (int) $order->discount_amount,
            (int) $order->shipping_cost,
            // Where the order WENT, read from the order — an edit in the
            // admin re-snapshots at the customer's address, not the admin's.
            Destinations::forOrder($order),
        );

        foreach ($lines as $line) {
            DB::table('m_taxes_order_taxes')->insert([
                'order_id' => $order->id,
                'tax_id' => $line->tax->id,
                'name' => $line->label(),
                'rate_type' => $line->tax->rate_type,
                'rate_percent' => $line->tax->rate_percent,
                'rate_amount' => $line->tax->rate_amount,
                'price_includes_tax' => $line->included(),
                'reg_number' => $line->tax->reg_number,
                'amount' => $line->amount,
            ]);
        }
    }

    /** @return list<array{product_id: int, unit_price: int, qty: int}> */
    protected function orderLines(Order $order): array
    {
        return array_values(array_map(fn (array $item) => [
            'product_id' => (int) $item['product_id'],
            'unit_price' => (int) $item['unit_price'],
            'qty' => (int) $item['qty'],
        ], $order->items ?? []));
    }
}
