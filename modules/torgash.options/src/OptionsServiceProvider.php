<?php

namespace Torgash\Options;

use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Pricing\Prices;

class OptionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PriceRules::class);
        $this->app->singleton(Groups::class);
        $this->app->singleton(Configurator::class);
        $this->app->singleton(FromPrice::class);

        // Per-request memos, and the reset that makes them true: under a
        // persistent worker a remembered set of groups would answer the next
        // visitor about the previous page's product.
        PerRequest::forget($this->app, Groups::class, Configurator::class, PriceRules::class, FromPrice::class);
    }

    public function boot(): void
    {
        // Its part of the demo shop (PLAN §55.3), after the catalogue's products.
        hook_listen('core.demo.parts', fn (array $parts) => $parts + [
            'options' => ['label' => 'Options', 'after' => ['catalog'], 'install' => fn (\App\Core\Demo\DemoPack $pack) => app(Demo\OptionsDemo::class)->install($pack)],
        ]);

        // The files customers attach to a choice are ours to declare on core's
        // storage screen and to its copy command — core does not name this table.
        hook_listen('core.files.directories', function (array $directories) {
            $row = \Torgash\Options\Models\OptionFile::query()->selectRaw('count(*) as files, coalesce(sum(size), 0) as bytes')->first();

            return [...$directories, [
                'disk' => \Torgash\Options\Models\OptionFile::DISK,
                'directory' => \Torgash\Options\Models\OptionFile::DIRECTORY,
                'label' => 'Files attached to options',
                'files' => (int) $row->files,
                'bytes' => (int) $row->bytes,
            ]];
        });

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        // Handing a customer's own file back. OUTSIDE StorefrontRoutes on
        // purpose: a signature covers one exact address, and a storefront route
        // exists once per locale — the trap `OrderKey`'s docblock names, where
        // the language switcher turns a working link into a 404.
        Route::middleware('web')
            ->get('/options/file/{file}', Http\FileController::class)
            ->name('options.file');

        Route::middleware(['web', 'auth', 'can:catalog.manage'])
            ->prefix('admin/options')
            ->name('admin.options.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        $this->registerCartSeams();
        $this->registerFiles();
        $this->registerFromPrice();
        $this->registerOrderEditor();
        $this->registerProductPage();
        $this->registerAdminTab();
        $this->registerCurrencyHooks();

        // A question whose every answer has sold out is not drawn at all, and
        // a REQUIRED one that is not drawn makes the product unbuyable without
        // saying so anywhere. That is a statement about our own data, so we
        // state it; a filter nobody fires when `torgash.guard` is absent.
        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\OptionInvariants)->register());

        // The configurator in a spreadsheet. Same soft dependency on exim as
        // the catalogue's own registrations: hooks only, nothing fires unless
        // exim asks.
        (new Exim\OptionSchema)->register();
    }

    /**
     * The three seams `torgash.cart` offers, and the whole of what cart knows
     * about options (PLAN §33.3).
     *
     * `cart.line.config` is where a submitted configuration becomes the one
     * canonical spelling the line key is computed from — and where an
     * impossible one is refused, by throwing, which the controller turns back
     * into the form. `cart.line.parts` is what the configuration adds.
     */
    protected function registerCartSeams(): void
    {
        hook_listen('cart.line.config', function (array $config, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $config;
            }

            // Files first, and before the "nothing was configured" shortcut: a
            // product whose only question is "attach your layout" arrives with
            // an EMPTY bag, because a file does not travel in the request's
            // input. Taking it here turns it into a token, and everything from
            // this line on deals in tokens only.
            $absorbed = app(Files::class)->absorb(
                app(Groups::class)->for($product),
                $config,
                $context['cart_token'] ?? null,
            );

            if ($absorbed['errors'] !== []) {
                throw ValidationException::withMessages($absorbed['errors']);
            }

            $config = $absorbed['config'];

            // Deliberately NO "nothing was submitted, nothing to do" shortcut.
            // It looked free and was a hole: a required question is refused by
            // `validate()`, so a POST that simply omitted the configuration
            // bought a product that cannot be bought without answering — and
            // the storefront form always sends something, which is exactly why
            // nobody noticed. A product with no questions still costs nothing
            // here: `Groups::for()` is memoised and both calls walk an empty
            // list.
            $configurator = app(Configurator::class);
            $normalised = $configurator->normalise($product, $config);
            $configurator->validate($product, $normalised);

            return $normalised;
        });

        // The same spelling for a QUOTE (Lines::spelled()): no file taken, no
        // answer refused — a kit pricing the product as configured so far.
        hook_listen('cart.line.spell', function (array $config, array $context) {
            $product = $context['product'] ?? null;

            return $product instanceof Product ? app(Configurator::class)->normalise($product, $config) : $config;
        });

        hook_listen('cart.line.parts', function (array $parts, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $parts;
            }

            return array_merge($parts, app(Configurator::class)->parts(
                $product,
                (int) ($context['qty'] ?? 1),
                (array) ($context['config'] ?? []),
                // The price the answers are measured against, when the caller
                // knows better than the catalogue does: a placed order's line
                // carries the price its product was sold at, and that is what
                // a percentage of it means there.
                isset($context['base']) ? (int) $context['base'] : null,
            ));
        });

        // Again at placement, and deliberately not because anybody mistrusts
        // the check above: a cart can sit for a week, and in that week a choice
        // is switched off, a linked product runs out, a category stops holding
        // the processor somebody picked. PlaceOrder is the last moment anyone
        // can say so — and it is the moment the API and one-click share.
        hook_listen('checkout.order.items', function (array $items, array $context) {
            $cart = $context['cart'] ?? null;

            if (! $cart || ! method_exists($cart, 'raw')) {
                return $items;
            }

            $configurator = app(Configurator::class);

            foreach ($cart->raw() as $line) {
                if (($line['config'] ?? []) === []) {
                    continue;
                }

                $product = Product::reachable()->find($line['product_id']);

                if ($product) {
                    $configurator->validate($product, (array) $line['config']);
                }
            }

            return $items;
        });
    }

    /**
     * What happens to a customer's file once it is in a basket (PLAN §33.7).
     *
     * Two things, and they are the same statement seen from both ends: a file
     * an order holds is kept for as long as the order is, and a file nobody
     * bought is thrown away. Without the first, the sweep would delete the
     * layout somebody paid to have printed a month after they ordered it;
     * without the second, every abandoned basket leaves 30 MB on the disk for
     * ever.
     *
     * Claimed on placement AND on any later change to an order's contents,
     * because a manager re-configuring a line (§33.7) attaches a file to an
     * order that was placed long ago — and that file arrived through no
     * basket at all, so nothing else would ever claim it.
     */
    protected function registerFiles(): void
    {
        $claim = function ($order) {
            if (! $order || ! $order->id) {
                return;
            }

            $tokens = [];

            foreach ((array) $order->items as $item) {
                foreach ((array) ($item['config'][Configurator::KEY] ?? []) as $entries) {
                    foreach ((array) $entries as $entry) {
                        if (is_array($entry) && ($entry['f'] ?? '') !== '') {
                            $tokens[] = (string) $entry['f'];
                        }
                    }
                }
            }

            app(Files::class)->claim($tokens, (int) $order->id);
        };

        hook_listen('checkout.order_placed', fn (array $context) => $claim($context['order'] ?? null));
        hook_listen('checkout.order.changed', fn (array $context) => $claim($context['order'] ?? null));

        // On the framework scheduler through core's own hook, never a job of
        // our own — the shape every module adds periodic work in.
        hook_listen('core.schedule', function (array $context) {
            $context['schedule']
                ->call(fn () => app(Files::class)->sweep())
                ->daily()
                ->name('options:sweep-files')
                ->withoutOverlapping();
        });
    }

    /**
     * Changing a build after the order was placed (PLAN §33.7).
     *
     * Checkout owns the order, the transaction, the stock and the log line;
     * this module owns the questions. So it answers two: whether there is
     * anything to configure — asked of every row the items table draws, hence
     * the cheap one — and the controls themselves, which are the storefront's
     * own form with its live repricing turned off and the ORDER's base price
     * in it. The same form deliberately: a second set of controls for the same
     * questions is a second thing to keep in step with every new kind of
     * question.
     *
     * Refusing is not answered here at all: the admin's submission goes
     * through `cart.line.config` exactly as the shop's does, so a choice the
     * shop has switched off cannot be sold from the back office either.
     */
    protected function registerOrderEditor(): void
    {
        hook_listen('checkout.order.line_configurable', function (bool $configurable, array $context) {
            $product = $context['product'] ?? null;

            return $configurable || ($product instanceof Product && app(Groups::class)->has($product));
        });

        hook_listen('checkout.order.line_config_form', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $html;
            }

            $config = (array) ($context['config'] ?? []);
            $groups = app(Groups::class)->asked($product, (array) ($config[Configurator::KEY] ?? []));

            if ($groups->isEmpty()) {
                return $html;
            }

            $line = (array) ($context['line'] ?? []);

            return $html.view('torgash.options::storefront.fields', [
                'product' => $product,
                'groups' => $groups,
                'config' => $config,
                'errors' => (array) ($context['errors'] ?? []),
                'optionsLive' => false,
                // What the order sold the product for, which is what a
                // percentage of it means on this line. Absent on a line placed
                // before configurations were stored, and then the catalogue's
                // own price is the only answer anybody has.
                'optionsPriceBase' => isset($line['base_price'])
                    ? (int) $line['base_price']
                    : null,
            ])->render();
        });
    }

    /**
     * What a card, a page and a feed print for a product that cannot be bought
     * at its own price (PLAN §33.7).
     *
     * The catalogue asks; this module answers, because only it knows what is
     * demanded and what the cheapest answer costs. `max()` rather than a bare
     * return: the filter chains, and another module raising the floor for its
     * own reason must not be undone by this one.
     *
     * The set is primed first. A grid of twelve servers asks this twelve
     * times, and without the announcement each question would be its own query
     * for that product's groups — the N+1 nothing in the markup makes visible.
     */
    protected function registerFromPrice(): void
    {
        hook_listen('catalog.products.listed', function (array $context) {
            app(Groups::class)->prime($context['products'] ?? []);
        });

        // A list's buy button (torgash.cart) asks whether this product has
        // anything to ask; if it does, the list opens the choice window instead
        // of dropping it into the basket unasked. Primed above for the page.
        hook_listen('cart.product.asks', function (bool $asks, array $context) {
            $product = $context['product'] ?? null;

            return $asks || ($product instanceof Product && app(Groups::class)->has($product));
        });

        hook_listen('catalog.price.from', function (int $minimum, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $minimum;
            }

            return max($minimum, app(FromPrice::class)->minimum(
                $product,
                (int) ($context['base'] ?? $product->effectivePrice()),
            ));
        });
    }

    /**
     * The form itself, inside the buy box (`cart.buy_box.fields`) so the
     * answers ride the same POST as the quantity, and a note on the card for a
     * product that cannot be bought without answering something.
     */
    protected function registerProductPage(): void
    {
        hook_listen('cart.buy_box.fields', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $html;
            }

            $config = (array) ($context['config'] ?? $this->lineConfig($product));

            // What this build asks, not what the product could ask: a question
            // that hangs off an unanswered one is not drawn at all.
            $groups = app(Groups::class)->asked(
                $product,
                (array) ($config[Configurator::KEY] ?? []),
            );

            if ($groups->isEmpty()) {
                return $html;
            }

            return $html.view('torgash.options::storefront.fields', [
                'product' => $product,
                'groups' => $groups,
                // What is already answered: what came back from a refused
                // form, or — when the page was opened from the cart with
                // '?line=' — the build that line was bought as. That is the
                // whole of "change this build": the same form, prefilled, and
                // adding it again merges into the same line because the key is
                // computed from the answers.
                'config' => $config,
                'errors' => (array) ($context['errors'] ?? []),
            ])->render();
        });
    }

    /**
     * The build a cart line holds, when the product page was opened from it.
     *
     * Asked of the cart rather than of the URL: the key names a line, and only
     * the cart can say whether it is this visitor's and what is in it.
     *
     * @return array<string, mixed>
     */
    protected function lineConfig(Product $product): array
    {
        $key = (string) request()->query('line', '');

        if ($key === '') {
            return [];
        }

        $line = app(\Torgash\Cart\CartManager::class)->line($key);

        return ($line && (int) $line['product_id'] === (int) $product->id) ? (array) $line['config'] : [];
    }

    /**
     * The product form's own tab — links, not an editor, exactly as the
     * product tabs screen is reached from a product: a group is a question
     * with answers, which is a form of its own, and a second way to edit it
     * would be a second place for it to be wrong.
     */
    protected function registerAdminTab(): void
    {
        hook_listen('catalog.product.form_tabs', function (array $tabs, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $tabs;
            }

            $tabs[] = [
                'key' => 'options',
                'label' => __('Options'),
                'html' => view('torgash.options::admin.product-tab', [
                    'product' => $product,
                    'own' => $product->exists
                        ? Models\OptionGroup::with('choices')->where('product_id', $product->id)->orderBy('sort')->get()
                        : collect(),
                    'shared' => $product->exists ? app(Groups::class)->for($product)->filter->isShared() : collect(),
                    'offered' => Models\OptionGroup::whereNull('product_id')->orderBy('admin_name')->get(),
                    'excluded' => $product->exists
                        ? DB::table('m_options_group_exclusions')->where('product_id', $product->id)->pluck('group_id')->map('intval')->all()
                        : [],
                ])->render(),
            ];

            return $tabs;
        });
    }

    /**
     * An option's amount is money, so it obeys the money rule without
     * exception: the typed number is the truth and the books column is
     * derived. A rate that moves reprices every option typed in that currency;
     * a currency the shop deletes leaves the books amount it last had.
     */
    protected function registerCurrencyHooks(): void
    {
        hook_listen('core.currency.rate_changed', function (array $context) {
            $code = strtoupper((string) ($context['code'] ?? ''));
            // The NEW rate, as core hands it over — never read back from
            // the table, which is what a listener racing the memo flush would
            // be doing.
            $rate = (float) ($context['to'] ?? 0);

            if ($code === '' || $rate <= 0) {
                return;
            }

            // Formatted rather than bound: it goes inside round(), and the rate
            // is a number we produced ourselves — the same call Prices makes.
            $divisor = sprintf('%.8F', $rate);

            DB::table('m_options_choices')
                ->where('currency', $code)
                ->whereNotNull('amount_original')
                ->update(['amount' => DB::raw("round(amount_original / {$divisor})")]);
        });

        hook_listen('core.currency.deleted', function (array $context) {
            DB::table('m_options_choices')
                ->where('currency', strtoupper((string) ($context['code'] ?? '')))
                ->update(['currency' => null, 'amount_original' => null]);
        });
    }

    /** What an admin screen uses to turn typed money into the books' own. */
    public static function prices(): Prices
    {
        return app(Prices::class);
    }
}
