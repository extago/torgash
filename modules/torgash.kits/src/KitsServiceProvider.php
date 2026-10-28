<?php

namespace Torgash\Kits;

use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;

class KitsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Kits::class);
        $this->app->singleton(Offer::class);
        $this->app->singleton(Discounts::class);

        PerRequest::forget($this->app, Kits::class, Discounts::class);
    }

    public function boot(): void
    {
        // A kit's dates offer and withdraw it with nothing written: a page
        // kept ready for guests is kept only until the next (PLAN §61).
        page_cache()->changes(fn () => page_cache()->nextDateIn('m_kits_kits'));

        // Its part of the demo shop (PLAN §55.3), after the catalogue's products.
        hook_listen('core.demo.parts', fn (array $parts) => $parts + [
            'kits' => ['label' => 'Kits', 'after' => ['catalog'], 'install' => fn (\App\Core\Demo\DemoPack $pack) => app(Demo\KitsDemo::class)->install($pack)],
        ]);

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        Route::middleware(['web', 'auth', 'can:catalog.manage'])
            ->prefix('admin/kits')
            ->name('admin.kits.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        $this->registerCartSeams();
        $this->registerProductPage();
        $this->registerAdminTab();

        // A kit breaks silently by design — an item with nothing to put in it
        // is simply not drawn — so the only way a shop hears about it is a
        // statement somebody goes and checks. A filter nobody fires when
        // `torgash.guard` is not installed.
        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\KitInvariants)->register());

        // Kits in a spreadsheet. torgash.exim is not in this module's requires
        // and need not be: this only populates the hook bus, and nothing fires
        // unless exim itself asks.
        (new Exim\KitSchema)->register();
    }

    /**
     * The one thing the cart learns about kits: an extended set is a line with
     * PARTS, and each part is one of the goods in the set at its price inside
     * it (PLAN §33.5, revised 2026-09-20).
     *
     * Through the same `cart.line.parts` seam an option's linked product uses,
     * so the cart, the order, 1C and a refund need to know nothing about kits:
     * a part that is a product becomes its own order line with `parent_key`.
     * The line's configuration bag is read under this module's own key and
     * nobody else's.
     */
    protected function registerCartSeams(): void
    {
        hook_listen('cart.line.parts', function (array $parts, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $parts;
            }

            return array_merge($parts, app(Discounts::class)->parts(
                $product,
                (int) ($context['qty'] ?? 1),
                (array) ($context['config'] ?? []),
            ));
        });

        // One line, one discount — the owner's rule (§33.8). Answered here
        // rather than known by those modules: `promotions.cart_lines` and
        // `checkout.coupon.base` ask "is there anything that must not be
        // discounted twice", and kits is what answers today.
        //
        // **The money in the SET, not the line it sits on.** An extended set is
        // one line — the main product with the rest inside it — and only the
        // rest is already cheaper. Dropping the whole line would take a
        // coupon off a full-price phone because the customer accepted a
        // discounted case with it, which is not what §33.8 says; it says the
        // main product is discountable and the kit's goods are not.
        hook_listen('promotions.cart_lines', function (array $lines) {
            // An ORDER has a line per goods, so the pieces of a set are rows
            // of their own here, hanging off the line that was bought.
            $kitKeys = [];

            foreach ($lines as $line) {
                if (isset($line['config'][Discounts::KEY])) {
                    $kitKeys[] = (string) ($line['key'] ?? '');
                }
            }

            $kept = [];

            foreach ($lines as $line) {
                // A piece of a set: already cheaper, and never discounted again.
                if (in_array((string) ($line['parent_key'] ?? ''), $kitKeys, true)) {
                    continue;
                }

                // A discount some other module wrote as a negative part.
                if ($line['discounted'] ?? false) {
                    continue;
                }

                // A BASKET line bought as a set: take the set's own money out
                // of it and leave the main product's.
                if (isset($line['config'][Discounts::KEY])) {
                    $line['line_total'] = max(0, (int) $line['line_total'] - $this->kitMoney($line));
                }

                if ((int) $line['line_total'] > 0) {
                    $kept[] = $line;
                }
            }

            return $kept;
        });

        hook_listen('checkout.coupon.base', function (int $base, array $context) {
            $cart = $context['cart'] ?? null;

            if (! $cart || ! method_exists($cart, 'items')) {
                return $base;
            }

            foreach ($cart->items() as $item) {
                if (isset(((array) $item->config)[Discounts::KEY])) {
                    $base -= $this->kitMoney(['parts' => $item->parts, 'qty' => $item->qty]);

                    continue;
                }

                foreach ($item->parts as $part) {
                    if ($part['amount'] < 0) {
                        $base -= $item->line_total;

                        break;
                    }
                }
            }

            return max(0, $base);
        });
    }

    /**
     * How much of a line is the SET rather than the product the set is built
     * on — the money that is already cheaper and may not be discounted twice.
     *
     * Read from the parts this module put there, by their own code, so a line
     * carrying an option's parts as well is not mistaken for one that is all
     * kit.
     *
     * @param  array<string, mixed>  $line
     */
    protected function kitMoney(array $line): int
    {
        $qty = max(1, (int) ($line['qty'] ?? 1));
        $money = 0;

        foreach ((array) ($line['parts'] ?? []) as $part) {
            if (str_starts_with((string) ($part['code'] ?? ''), 'kit')) {
                $money += \Torgash\Cart\Lines::partTotal($part, $qty);
            }
        }

        return $money;
    }

    /**
     * The card, where the shop asked for it: under the buy button, or as a
     * section of the product page's own strip (`catalog.product.tabs`, the same
     * seam reviews use).
     */
    protected function registerProductPage(): void
    {
        // Two zones, one listener each, because they are two WIDTHS: the
        // narrow column under the buy button and the full width of the page
        // above the tabs. Which one a kit is drawn in is the shop's own
        // choice, and this module does not care beyond matching the word.
        foreach (['before_details', 'after_buy_box'] as $placement) {
            hook_listen('catalog.product.'.$placement, function (string $html, array $context) use ($placement) {
                $product = $context['product'] ?? null;

                if (! $product instanceof Product) {
                    return $html;
                }

                foreach (app(Kits::class)->for($product) as $kit) {
                    if ($kit->placement !== $placement) {
                        continue;
                    }

                    $html .= $this->card($kit, $product);
                }

                return $html;
            });
        }

        // Told to the admin's list of product tabs: kits placed as a tab stand
        // at 25, whichever products they end up on.
        hook_listen('catalog.product.tabs.admin', function (array $rows) {
            $count = \Torgash\Kits\Models\Kit::where('placement', 'tab')->count();

            return $count === 0 ? $rows : [...$rows, [
                'key' => 'kits',
                'label' => __('Kits'),
                'sort' => 25,
                'module' => __('Kits'),
                'note' => trans_choice(':count kit placed as a tab, on the products it is written for|:count kits placed as a tab, on the products they are written for', $count, ['count' => $count]),
                'url' => url('/admin/kits'),
                'renamable' => false,
            ]];
        });

        hook_listen('catalog.product.tabs', function (array $sections, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $sections;
            }

            foreach (app(Kits::class)->for($product) as $kit) {
                if ($kit->placement !== 'tab') {
                    continue;
                }

                $card = $this->card($kit, $product);

                if ($card === '') {
                    continue;
                }

                $sections[] = [
                    'key' => 'kit-'.$kit->id,
                    // Every kit's tab answers to ONE row ("kits"), which may move,
                    // hide or rule them out — but not rename them: each kit is
                    // called what the shop called it.
                    'section_key' => 'kits',
                    'fixed_label' => true,
                    'label' => $kit->t('name'),
                    'heading' => $kit->t('name'),
                    'sort' => 25,
                    'html' => $card,
                ];
            }

            return $sections;
        });
    }

    /** A kit that cannot be filled for this product draws nothing at all. */
    protected function card($kit, Product $product): string
    {
        $offer = app(Offer::class)->for($kit, $product);

        return $offer ? view('torgash.kits::storefront.card', ['offer' => $offer])->render() : '';
    }

    /**
     * The product form's own tab — links, like the options module's: a kit with
     * its slots is a form of its own.
     */
    protected function registerAdminTab(): void
    {
        hook_listen('catalog.product.form_tabs', function (array $tabs, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $tabs;
            }

            $tabs[] = [
                'key' => 'kits',
                'label' => __('Kits'),
                'html' => view('torgash.kits::admin.product-tab', [
                    'product' => $product,
                    'own' => $product->exists
                        ? Models\Kit::with('slots')->where('main_product_id', $product->id)->orderBy('sort')->get()
                        : collect(),
                    'shared' => $product->exists
                        ? app(Kits::class)->for($product)->filter->isShared()
                        : collect(),
                ])->render(),
            ];

            return $tabs;
        });
    }
}
