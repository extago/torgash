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
        $this->app->singleton(Earning::class);
        $this->app->singleton(PointPrices::class);
        PerRequest::forget($this->app, Ledger::class, Earning::class, PointPrices::class);
    }

    public function boot(): void
    {
        // Customers' points and their history are on no guest's page (PLAN §61).
        page_cache()->unseen(['m_points_accounts', 'm_points_ledger', 'm_points_orders']);

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
        });

        $this->checkout();
        $this->orders();
        $this->catalog();
        $this->people();
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

        hook_listen('checkout.order.payment_extra', function (string $html, array $context) {
            $order = $context['order'];
            $row = $order->user_id ? OrderPoints::find($order->id) : null;

            if (! $row || ! auth()->user()?->can('points.manage')) {
                return $html;
            }

            return $html.view('torgash.points::admin.order-panel', ['order' => $order, 'pointsRow' => $row])->render();
        });
    }

    protected function catalog(): void
    {
        hook_listen('catalog.products.listed', fn (array $context) => app(Earning::class)->prime($context['products'] ?? []));

        hook_listen('catalog.product.card_meta', function (string $html, array $context) {
            $product = $context['product'] ?? null;

            return $product instanceof Product ? $html.$this->badge($product, 'card') : $html;
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
                    'pointsPrice' => $product->exists ? \Illuminate\Support\Facades\DB::table('m_points_products')->where('product_id', $product->id)->value('points_price') : null,
                    'pointsMultiplier' => $product->exists ? \Illuminate\Support\Facades\DB::table('m_points_rules')->where('kind', 'product')->where('target_id', $product->id)->value('multiplier') : null,
                ])->render(),
            ];

            return $tabs;
        });

        hook_listen('catalog.product.saved', function (array $context) {
            $request = $context['request'] ?? null;

            if (! $request || ! $request->has('points_form')) {
                return;
            }

            $id = $context['product']->id;
            $db = \Illuminate\Support\Facades\DB::table('m_points_products');
            $price = (int) $request->input('points_price');
            $price > 0
                ? $db->updateOrInsert(['product_id' => $id], ['points_price' => $price, 'updated_at' => now(), 'created_at' => now()])
                : $db->where('product_id', $id)->delete();

            $rules = \Illuminate\Support\Facades\DB::table('m_points_rules');
            $multiplier = $request->input('points_multiplier');
            is_numeric($multiplier)
                ? $rules->updateOrInsert(['kind' => 'product', 'target_id' => $id], ['multiplier' => (float) $multiplier, 'updated_at' => now(), 'created_at' => now()])
                : $rules->where('kind', 'product')->where('target_id', $id)->delete();
        });
    }

    /** "+N points" and, when the shop sells for points, "or N points". */
    protected function badge(Product $product, string $where): string
    {
        $user = auth()->user();
        $earn = setting('torgash.points', 'show_earn', true)
            ? app(Earning::class)->forPrice($product, (int) $product->effectivePrice(), $user)
            : 0;
        $price = app(PointPrices::class)->for($product);

        if ($earn <= 0 && $price === null) {
            return '';
        }

        return view('torgash.points::storefront.badge', [
            'pointsEarn' => $earn,
            'pointsPrice' => $price,
            'pointsWhere' => $where,
            'pointsGuest' => ! $user,
        ])->render();
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
