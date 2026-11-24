<?php

namespace Torgash\Points;

use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Points\Mail\PointsLetters;
use Torgash\Points\Models\OrderPoints;

/**
 * Bonus points (PLAN §40.5), wired in. Checkout knows nothing of points: it
 * asks `checkout.cart.discounts` what else comes off after the coupon, freezes
 * the answer on the order, and lets `checkout.order.placing` spend them inside
 * its own transaction.
 */
class PointsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Ledger::class);
        $this->app->singleton(Rules::class);
        $this->app->singleton(Earning::class);
        $this->app->singleton(PointPrices::class);
        $this->app->singleton(ProductTerms::class);
        PerRequest::forget($this->app, Ledger::class, Rules::class, Earning::class, PointPrices::class, ProductTerms::class);
    }

    public function boot(): void
    {
        // Spending a customer's points on their order (PLAN §70).
        hook_listen('core.impersonation.writes', fn (array $routes) => [...$routes, 'points.spend', 'points.line']);

        // Customers' points and their history are on no guest's page (PLAN §61).
        page_cache()->unseen(['m_points_accounts', 'm_points_ledger', 'm_points_orders', 'm_points_payment_methods']);

        // "Has points", for the newsletter's campaigns: a reminder that a balance is waiting.
        hook_listen('newsletter.segments', fn (array $kinds) => $kinds + [
            'points' => [
                'label' => 'Has points',
                'fields' => [['key' => 'min', 'type' => 'number', 'label' => 'At least', 'help' => 'Empty — any balance above zero.']],
                'apply' => fn (\Illuminate\Database\Eloquent\Builder $q, array $p) => $q->whereExists(fn ($a) => $a->selectRaw('1')
                    ->from('m_points_accounts')
                    ->join('users', 'users.id', '=', 'm_points_accounts.user_id')
                    ->where(fn ($mine) => $mine->whereColumn('m_points_accounts.user_id', 'm_newsletter_subscribers.user_id')
                        ->orWhereRaw('lower(users.email) = m_newsletter_subscribers.email'))
                    ->where('m_points_accounts.balance', '>=', max(1, (int) ($p['min'] ?? 0)))),
            ],
        ]);

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        Route::middleware(['web', 'auth', 'can:points.manage'])
            ->prefix('admin/points')
            ->name('admin.points.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        hook_listen('api.resources', fn () => (new Api\PointsResources)->register());

        hook_listen('core.seo.private_routes', fn (array $names) => [...$names, 'points.*', 'account.points.*']);
        hook_listen('core.mail.events', fn (array $events) => $events + PointsLetters::events());
        hook_listen('guard.invariants', fn (array $all) => $all + (new Invariants\PointsInvariants)->register());

        hook_listen('core.settings.fields', function (array $fields, array $context) {
            if (($context['module'] ?? null) !== 'torgash.points') {
                return $fields;
            }

            return array_map(function (array $field) {
                if (in_array($field['key'] ?? null, ['earn_statuses', 'revoke_statuses'], true)) {
                    $field['options'] = \Torgash\Checkout\Models\OrderStatus::orderBy('position')->get()
                        ->mapWithKeys(fn ($status) => [$status->code => $status->t('name')])->all();
                }

                return $field;
            }, $fields);
        });

        hook_listen('core.schedule', function (array $context) {
            $context['schedule']->call(fn () => app(Ledger::class)->expire())->dailyAt('03:20')->name('points:expire');
            // A letter is read in the morning, not found at night.
            $context['schedule']->call(fn () => app(ExpiryNotices::class)->send())->dailyAt('10:10')->name('points:expiry-notices');
        });

        $this->checkout();
        $this->orders();
        $this->catalog();
        $this->people();
        $this->manyProducts();
        $this->gifts();
    }

    /**
     * Points the shop gives beyond what goods earn (PLAN §74, stage 3): a
     * cart promotion's "Give bonus points" — the order's own, waiting and
     * credited with the rest — and an automation rule's action, given at once.
     * Both are answers to the other module's question: a shop without
     * promotions or automations never asks.
     */
    protected function gifts(): void
    {
        hook_listen('promotions.side_effects', fn (array $effects) => $effects + [
            'give_points' => [
                'label' => 'Give bonus points',
                'value_label' => 'Points',
                'icon' => 'coins',
                'describe' => fn (int $value) => trans_choice('+:points bonus point|+:points bonus points', $value, ['points' => number_format($value, 0, '.', ' ')]),
            ],
        ]);

        hook_listen('promotions.order.effects', fn (array $context) => ($context['order'] ?? null) instanceof Order
            ? app(OrderFlow::class)->promoted($context['order'], (array) ($context['effects'] ?? []))
            : null);

        hook_listen('automations.actions', fn (array $actions) => $actions + [
            'give_points' => Automations\GivePointsAction::class,
        ]);
    }

    /**
     * A product's own answers for many products at once (PLAN §74, stage 2):
     * three columns of the product exchange, spelled so that an export goes
     * through a spreadsheet and back ({@see ProductTerms}), and a section of
     * the products list's "Change…" dialog.
     */
    protected function manyProducts(): void
    {
        $column = fn (string $key, string $label, string $help, array $aliases, \Closure $get, \Closure $after) => [
            'key' => $key,
            'group' => 'prices',
            'label' => $label,
            'help' => $help,
            'aliases' => $aliases,
            // Not part of "every field": a feed nobody changed grows no columns.
            'on_request' => true,
            'get' => fn (Product $product) => $get(app(ProductTerms::class)->row((int) $product->id)),
            'set' => null,
            'after' => $after,
        ];
        $refused = function (string $label, $value, array $ctx): void {
            if (($ctx['batch'] ?? null) instanceof \Torgash\Exim\Import\ImportBatch) {
                $ctx['batch']->note('Line :line: ":value" is not an answer for ":column" — left as it was.', ['value' => (string) $value, 'column' => __($label)]);
            }
        };

        hook_listen('exim.schema.product', fn (array $fields) => [...$fields,
            $column('points_earn', 'Bonus points: earns', 'Empty — as the rules say; x2 — the shop\'s rate doubled; 5% — of the price; 500 — points per piece; none.', [],
                fn (?object $row) => ProductTerms::spellEarn($row),
                function (Product $product, $value, array $ctx = []) use ($refused) {
                    ($earn = ProductTerms::parseEarn($value)) === null
                        ? $refused('Bonus points: earns', $value, $ctx)
                        : app(ProductTerms::class)->write((int) $product->id, ['earn' => $earn]);
                }),
            $column('points_pay', 'Bonus points: paying with points', 'Empty — as the rules say; yes; no; 30% — up to its own share of the price.', ['Pay by points'],
                fn (?object $row) => ProductTerms::spellPay($row),
                function (Product $product, $value, array $ctx = []) use ($refused) {
                    ($spend = ProductTerms::parsePay($value)) === null
                        ? $refused('Bonus points: paying with points', $value, $ctx)
                        : app(ProductTerms::class)->write((int) $product->id, ['spend' => $spend]);
                }),
            $column('points_price', 'Bonus points: price in points', 'A whole number; empty — none.', ['Price in points'],
                fn (?object $row) => (string) ($row->points_price ?? ''),
                fn (Product $product, $value) => app(ProductTerms::class)->write((int) $product->id, ['price' => (int) preg_replace('/\D/', '', (string) $value)])),
        ]);

        // An answer changed is a change of the product feed that has the column.
        hook_listen('exim.watch.product', fn (array $tables) => [...$tables, 'm_points_products']);

        hook_listen('catalog.products.bulk.fields', fn (string $html) => $html.view('torgash.points::admin.bulk-fields')->render());

        hook_listen('catalog.products.bulk.changes', function (array $changes, array $context) {
            $request = $context['request'];
            $mine = [];
            $earn = (string) $request->input('points_bulk_earn');
            $spend = (string) $request->input('points_bulk_spend');

            if ($earn === 'default' || $earn === 'none' || (in_array($earn, Rules::EARN_MODES, true) && is_numeric($request->input('points_bulk_earn_value')))) {
                $mine['earn'] = [$earn, $request->input('points_bulk_earn_value')];
            }
            if (in_array($spend, ['default', 'allowed', 'forbidden'], true) || ($spend === 'limit' && is_numeric($request->input('points_bulk_spend_percent')))) {
                $mine['spend'] = [$spend, $request->input('points_bulk_spend_percent')];
            }

            return $mine === [] ? $changes : $changes + ['torgash.points' => $mine];
        });

        hook_listen('catalog.products.bulk.apply', function (array $context) {
            $mine = $context['changes']['torgash.points'] ?? null;

            foreach ($mine ? $context['products'] : [] as $product) {
                app(ProductTerms::class)->write((int) $product->id, $mine);
            }
        });
    }

    protected function checkout(): void
    {
        hook_listen('checkout.cart.discounts', function (array $lines, array $context) {
            $line = app(Spending::class)->quote($context['cart'], (int) $context['base']);

            return $line ? [...$lines, $line] : $lines;
        });

        // The box to spend them, on the cart page and under the checkout's code box.
        hook_listen('cart.rows_before_total', function (string $html, array $context) {
            return $html.$this->box($context['cart'], 'cart');
        }, 30);

        hook_listen('checkout.code.after', function (string $html, array $context) {
            return ($context['in'] ?? null) === 'checkout' ? $html.$this->box(app(CartManager::class), 'checkout') : $html;
        });

        // "Buy this line for points", beside the line's bin.
        hook_listen('cart.line.actions', function (string $html, array $context) {
            $line = $context['line'] ?? null;
            $price = $line ? app(PointPrices::class)->for($context['product'] ?? null) : null;

            if ($price === null || ! auth()->check()) {
                return $html;
            }

            $cart = app(CartManager::class);

            return $html.view('torgash.points::storefront.line-toggle', [
                'pointsLine' => $line,
                'pointsCost' => $price * (int) $line->qty,
                'pointsOn' => in_array((string) $line->key, app(Spending::class)->lines($cart), true),
            ])->render();
        });
    }

    protected function box(CartManager $cart, string $in): string
    {
        $user = auth()->user();
        $spending = app(Spending::class);

        if (! $user) {
            return '';
        }

        $balance = app(Ledger::class)->balance($user->id);

        if ($balance <= 0 && $spending->requested($cart) === 0) {
            return '';
        }

        $coupons = app(\Torgash\Checkout\CouponManager::class);
        $base = max(0, (int) $cart->total() - $coupons->couponDiscount());
        $line = collect($coupons->otherDiscounts())->firstWhere('code', 'points');

        return view('torgash.points::storefront.box', [
            'pointsBalance' => $balance,
            'pointsMax' => $spending->maximum($cart, $base),
            'pointsAsked' => $spending->requested($cart),
            'pointsLine' => $line,
            'pointsBlocked' => ! setting('torgash.points', 'with_coupon', true) && $coupons->current(),
            'pointsRestricted' => $spending->restricted($cart),
            'pointsFrom' => $spending->tooSmall($cart) ? $spending->orderFrom() : 0,
            'pointsInfoUrl' => Spending::infoUrl(),
            'in' => $in,
        ])->render();
    }

    protected function orders(): void
    {
        hook_listen('checkout.order.placing', fn (array $context) => app(OrderFlow::class)->placing($context['order']));
        hook_listen('checkout.order_placed', fn (array $context) => app(OrderFlow::class)->plan($context['order']));
        hook_listen('checkout.order_status_changed', fn (array $context) => app(OrderFlow::class)
            ->statusChanged($context['order'], (string) ($context['status']->code ?? '')));
        hook_listen('checkout.order.changed', fn (array $context) => ($context['order'] ?? null) instanceof Order
            ? app(OrderFlow::class)->changed($context['order']->fresh())
            : null);
        hook_listen('checkout.order.recalculated', fn (array $context) => app(OrderFlow::class)->recalculated($context['order']));

        // An order poured into another takes its spent points with it (PLAN
        // §40.10/4); one whose points were credited is not merged.
        hook_listen('checkout.order.merge_refusals', fn (array $refusals, array $context) => app(OrderMerging::class)->refusals($refusals, $context));
        hook_listen('checkout.order.merging', fn (array $context) => app(OrderMerging::class)->merge($context));
        hook_listen('checkout.order.unmerging', fn (array $context) => app(OrderMerging::class)->undo($context));

        // Points for the way of paying (PLAN §74, stage 4): set on the
        // method's own form, said beside it at checkout to whoever would
        // get them.
        hook_listen('checkout.payment_method.extra', fn (string $html, array $context) => $html.view('torgash.points::admin.payment-method-field', [
            'pointsPayment' => app(Rules::class)->payment($context['method']->exists ? (int) $context['method']->id : null),
        ])->render());

        hook_listen('checkout.payment_method.saved', function (array $context) {
            $request = $context['request'];

            // Absent field (another form, the list's switch) must not wipe it.
            if (! $request->has('points_payment_shown')) {
                return;
            }

            $value = round(max(0, (float) str_replace(',', '.', (string) $request->input('points_payment_value'))), 2);
            $table = \Illuminate\Support\Facades\DB::table('m_points_payment_methods');

            $value > 0
                ? $table->updateOrInsert(['payment_method_id' => $context['method']->id], [
                    'mode' => $request->input('points_payment_mode') === 'percent' ? 'percent' : 'fixed',
                    'value' => $request->input('points_payment_mode') === 'percent' ? min(100, $value) : $value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])
                : $table->where('payment_method_id', $context['method']->id)->delete();

            app(Rules::class)->flush();
        });

        hook_listen('checkout.payment_methods.hints', function (array $hints, array $context) {
            $cart = $context['cart'] ?? null;

            if (! auth()->check() || ! $cart instanceof CartManager) {
                return $hints;
            }

            $rules = app(Rules::class);
            $earning = app(Earning::class);
            $goods = null;

            foreach ($context['methods'] ?? [] as $method) {
                $pay = $rules->payment((int) $method->id);

                if ($pay['mode'] === 'none') {
                    continue;
                }

                $goods ??= $this->goodsThatEarn($cart);
                $points = $earning->forPayment($pay, $goods);

                if ($points > 0) {
                    $hints[$method->id][] = trans_choice('+:points bonus point|+:points bonus points', $points, ['points' => number_format($points, 0, '.', ' ')]);
                }
            }

            return $hints;
        });

        hook_listen('checkout.order.payment_extra', function (string $html, array $context) {
            $order = $context['order'];
            $row = $order->user_id ? OrderPoints::find($order->id) : null;

            if (! $row || ! auth()->user()?->can('points.manage')) {
                return $html;
            }

            return $html.view('torgash.points::admin.order-panel', ['order' => $order, 'pointsRow' => $row])->render();
        });
    }

    /**
     * The basket's goods a way of paying is rewarded for, as the order will
     * count them: inside the programme, after the coupon and whatever else
     * comes off (minor units).
     */
    protected function goodsThatEarn(CartManager $cart): int
    {
        $rules = app(Rules::class);
        $items = $cart->items();
        $rules->prime($items->map(fn ($item) => $item->product)->all());

        $goods = (int) $items->sum(fn ($item) => (int) $item->line_total);
        $inside = (int) $items->sum(fn ($item) => $rules->outside((int) $item->product->id) ? 0 : (int) $item->line_total);

        if ($goods <= 0 || $inside <= 0) {
            return 0;
        }

        $coupons = app(\Torgash\Checkout\CouponManager::class);
        $off = $coupons->couponDiscount() + (int) collect($coupons->otherDiscounts())->sum('amount');

        return (int) round($inside * max(0, $goods - $off) / $goods);
    }

    protected function catalog(): void
    {
        hook_listen('catalog.products.listed', fn (array $context) => app(Earning::class)->prime($context['products'] ?? []));

        // One of two places on a card, as the shop chose: the row under the
        // name (after the stars), or under the price. Every list that draws
        // the one draws the other (grid, list, wishlist, comparison).
        hook_listen('catalog.product.card_meta', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            return $product instanceof Product && setting('torgash.points', 'card_place') !== 'price'
                ? $html.$this->badge($product, 'card') : $html;
        });

        hook_listen('catalog.product.card_price_extra', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            return $product instanceof Product && setting('torgash.points', 'card_place') === 'price'
                ? $html.$this->badge($product, 'card') : $html;
        });

        hook_listen('catalog.product.price_extra', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            return $product instanceof Product ? $html.$this->badge($product, 'page') : $html;
        });

        hook_listen('catalog.product.form_tabs', function (array $tabs, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $tabs;
            }

            $tabs[] = [
                'key' => 'points',
                'section' => true,
                'label' => __('Bonus points'),
                'html' => view('torgash.points::admin.product-tab', [
                    'pointsOwn' => $product->exists ? \Illuminate\Support\Facades\DB::table('m_points_products')->where('product_id', $product->id)->first() : null,
                    'pointsNow' => $product->exists ? $this->explain($product) : null,
                ])->render(),
            ];

            return $tabs;
        });

        hook_listen('catalog.product.saved', function (array $context) {
            $request = $context['request'] ?? null;

            if (! $request || ! $request->has('points_form')) {
                return;
            }

            app(ProductTerms::class)->write((int) $context['product']->id, [
                'earn' => [(string) $request->input('points_earn_mode'), $request->input('points_earn_value')],
                'spend' => [(string) $request->input('points_spend_mode'), $request->input('points_spend_percent')],
                'price' => (int) $request->input('points_price'),
            ]);
        });
    }

    /**
     * What the product earns and how much of it points pay TODAY, and who
     * said so — printed on its Bonus points tab, so that nobody has to work a
     * list of rules out in their head. At its list price, for a customer of
     * no group.
     *
     * @return array{earn: int, earn_by: array<string, mixed>, spend: array<string, mixed>}
     */
    protected function explain(Product $product): array
    {
        $rules = app(Rules::class);
        $earning = app(Earning::class);
        $unit = price_scale()->toMinor((int) $product->price);
        $by = $rules->earn((int) $product->id, $unit, null);

        return [
            'earn' => (int) floor($earning->points($by, $unit, 1)),
            'earn_by' => $by,
            'spend' => $rules->spend((int) $product->id, $unit, null),
        ];
    }

    /** "+N points" and, when the shop sells for points, "or N points". */
    protected function badge(Product $product, string $where): string
    {
        $user = auth()->user();
        $earn = setting('torgash.points', 'show_earn', true)
            ? app(Earning::class)->forPrice($product, (int) $product->effectivePrice(), $user)
            : 0;
        $price = app(PointPrices::class)->for($product);
        $share = $where === 'page' ? $this->shareToSay($product, $user) : null;

        if ($earn <= 0 && $price === null && $share === null) {
            return '';
        }

        return view('torgash.points::storefront.badge', [
            'pointsEarn' => $earn,
            'pointsPrice' => $price,
            'pointsWhere' => $where,
            'pointsGuest' => ! $user,
            'pointsShare' => $share,
            'pointsInfoUrl' => $where === 'page' ? Spending::infoUrl() : null,
        ])->render();
    }

    /**
     * The share of this product points pay, when a product's page has
     * something to say about it: a part of the price, or — in a shop where
     * points do pay — an exception they do not pay for. Null where they pay
     * for the whole of it, or for nothing anywhere.
     */
    protected function shareToSay(Product $product, ?\App\Models\User $user): ?int
    {
        if (! setting('torgash.points', 'show_spend', true)) {
            return null;
        }

        $percent = app(Rules::class)->spend((int) $product->id, price_scale()->toMinor((int) $product->effectivePrice()), $user)['percent'];
        $usual = setting('torgash.points', 'spend_default', true) ? (int) setting('torgash.points', 'spend_max_percent', 50) : 0;

        return $percent >= 100 || ($percent <= 0 && $usual <= 0) ? null : $percent;
    }

    protected function people(): void
    {
        hook_listen('core.account.nav', fn (array $items) => [...$items, [
            'label' => __('Bonus points'),
            'group' => 'bonuses',
            'url' => route('account.points.index'),
            'icon' => 'coins',
            'active' => request()->routeIs('*account.points.*'),
            'badge' => auth()->check() ? (app(Ledger::class)->balance(auth()->id()) ?: null) : null,
        ]]);

        hook_listen('core.admin.customers.facts', function (array $facts, array $context) {
            $ids = (array) ($context['ids'] ?? []);

            if ($ids === [] || ! auth()->user()?->can('points.manage')) {
                return $facts;
            }

            foreach (\Illuminate\Support\Facades\DB::table('m_points_accounts')->whereIn('user_id', $ids)->where('balance', '!=', 0)->get() as $row) {
                $facts[$row->user_id][] = ['key' => 'points', 'label' => __('Bonus points'), 'value' => number_format((int) $row->balance, 0, '.', ' ')];
            }

            return $facts;
        });

        hook_listen('core.admin.customer.panels', function (array $panels, array $context) {
            $customer = $context['customer'] ?? null;

            if (! $customer || ! auth()->user()?->can('points.manage')) {
                return $panels;
            }

            return [...$panels, ['key' => 'points', 'position' => 35, 'html' => view('torgash.points::admin.customer-panel', [
                'pointsCustomer' => $customer,
                'pointsBalance' => app(Ledger::class)->balance($customer->id),
                'pointsEntries' => Models\Entry::where('user_id', $customer->id)->orderByDesc('id')->limit(10)->get(),
            ])->render()]];
        });
    }
}
