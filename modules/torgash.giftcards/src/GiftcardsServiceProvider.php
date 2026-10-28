<?php

namespace Torgash\Giftcards;

use App\Core\Storefronts\StorefrontRoutes;
use App\Core\Support\PerRequest;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Checkout\Orders\Refund;
use Torgash\Giftcards\Mail\GiftcardLetters;
use Torgash\Giftcards\Models\Card;

/**
 * Gift cards (PLAN §40.4), wired in. Checkout knows nothing of a gift card —
 * it knows that part of a bill can be PAID IN ADVANCE, and asks:
 *
 * - `checkout.code.apply` — a code in the coupon box that is not a coupon;
 * - `checkout.prepaid.quote` / `checkout.order.prepay` — what the cards on a
 *   basket pay, shown in the summary and then spent inside the order's own
 *   transaction;
 * - `checkout.order.prepaid_lines` / `checkout.order.refund_prepaid` — what
 *   they paid of a placed order, and putting it back.
 *
 * The cart knows even less: a gift card product is a product whose line
 * carries answers (`cart.line.config` / `cart.line.parts`), exactly as an
 * option's does.
 */
class GiftcardsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GiftConfig::class);
        PerRequest::forget($this->app, GiftConfig::class);
    }

    public function boot(): void
    {
        // Issued cards and what they paid for are on no guest's page (PLAN §61).
        page_cache()->unseen(['m_giftcards_cards', 'm_giftcards_ledger']);

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        Route::middleware(['web', 'auth', 'can:giftcards.manage'])
            ->prefix('admin/giftcards')
            ->name('admin.giftcards.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // An order poured into another takes its gift card money and the
        // cards it sold with it (PLAN §40.10/4).
        hook_listen('checkout.order.merging', fn (array $context) => app(OrderMerging::class)->merge($context));
        hook_listen('checkout.order.unmerging', fn (array $context) => app(OrderMerging::class)->undo($context));

        // The admin API's resource, and the shop API's balance check.
        hook_listen('api.resources', fn () => (new Api\GiftcardResources)->register());

        Route::middleware(['throttle:torgash-api'])
            ->get('api/v2/shop/giftcards/{code}', [Http\BalanceController::class, 'show'])
            ->name('api.shop.giftcards.balance');

        hook_listen('api.openapi.paths', fn (array $paths) => $paths + [
            '/api/v2/shop/giftcards/{code}' => ['get' => [
                'tags' => ['Gift cards'],
                'summary' => 'What is left on a card',
                'description' => 'By its code, in any spelling. Counted against the same wrong-code limit as the basket box: ten wrong codes from one address in a quarter of an hour and even a right one waits. The card is applied to a cart through `PATCH /carts/{token}/checkout` with `code`.',
                'parameters' => [['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
                'responses' => [
                    '200' => ['description' => 'The card: `last4`, `state`, `balance` (minor units), `expires_at`.'],
                    '404' => ['description' => 'No card has this code.'],
                    '429' => ['description' => 'Too many wrong codes from this address.'],
                ],
            ]],
        ]);

        // The shop API taking a card back off a cart by its id.
        hook_listen('checkout.prepaid.remove', function ($done, array $context) {
            app(Wallet::class)->remove($context['cart'], (int) ($context['id'] ?? 0));

            return true;
        });

        hook_listen('core.seo.private_routes', fn (array $names) => [...$names, 'giftcards.*', 'account.giftcards.*']);
        hook_listen('core.mail.events', fn (array $events) => $events + GiftcardLetters::events());

        $this->checkoutSeams();
        $this->placedOrders();
        $this->selling();
        $this->storefront();

        // The statuses a shop has are its own; the settings screen is told them.
        hook_listen('core.settings.fields', function (array $fields, array $context) {
            if (($context['module'] ?? null) !== 'torgash.giftcards') {
                return $fields;
            }

            return array_map(function (array $field) {
                if (($field['key'] ?? null) === 'return_statuses') {
                    $field['options'] = \Torgash\Checkout\Models\OrderStatus::orderBy('position')->get()
                        ->mapWithKeys(fn ($status) => [$status->code => $status->t('name')])->all();
                }

                return $field;
            }, $fields);
        });

        hook_listen('guard.invariants', fn (array $all) => $all + (new Invariants\GiftcardInvariants)->register());

        hook_listen('core.schedule', function (array $context) {
            $context['schedule']->call(fn () => app(Delivery::class)->sendDue())
                ->everyFiveMinutes()->name('giftcards:deliver');
            $context['schedule']->call(fn () => app(Expiry::class)->sweep())
                ->dailyAt('03:10')->name('giftcards:expire');
        });
    }

    /** The basket and the checkout: one code box, and money paid in advance. */
    protected function checkoutSeams(): void
    {
        hook_listen('checkout.code.apply', function ($answer, array $context) {
            return $answer ?? app(Wallet::class)->applyCode($context['cart'], (string) ($context['code'] ?? ''));
        });

        hook_listen('checkout.code.placeholder', fn () => __('Coupon or gift card?'));
        hook_listen('checkout.code.others', fn () => true);

        hook_listen('checkout.prepaid.quote', fn (array $lines, array $context) => [
            ...$lines,
            ...app(Wallet::class)->quote($context['cart'], (int) $context['total'], (int) ($context['shipping_cost'] ?? 0)),
        ]);

        hook_listen('checkout.order.prepay', fn (array $parts, array $context) => [
            ...$parts,
            ...app(Wallet::class)->capture($context['order'], $context['cart'], (int) $context['amount']),
        ]);

        // A signed-in customer's own cards, offered under the box without
        // typing a code (PLAN §40.4 "без ввода кода").
        hook_listen('checkout.code.after', function (string $html, array $context) {
            if (! auth()->check()) {
                return $html;
            }

            $cart = app(\Torgash\Cart\CartManager::class);
            $applied = array_column(app(Wallet::class)->applied($cart), 'id');
            $own = Card::where('owner_user_id', auth()->id())->whereNotIn('id', $applied ?: [0])->get()
                ->filter(fn (Card $card) => $card->isUsable())->values();

            return $own->isEmpty() ? $html : $html.view('torgash.giftcards::storefront.own-cards', [
                'ownCards' => $own,
                'in' => $context['in'] ?? 'cart',
            ])->render();
        });

        // The cart page: the cards already on the basket, above its total.
        hook_listen('cart.rows_before_total', function (string $html, array $context) {
            $cards = app(Wallet::class)->cards($context['cart']);

            return $cards->isEmpty() ? $html : $html.view('torgash.giftcards::storefront.cart-rows', ['cartCards' => $cards])->render();
        }, 20);
    }

    /** What the cards paid of an order, and what happens to it afterwards. */
    protected function placedOrders(): void
    {
        hook_listen('checkout.order.prepaid_lines', fn (array $lines, array $context) => [
            ...$lines,
            ...app(OrderCards::class)->lines($context['order']),
        ]);

        hook_listen('checkout.order.refund_prepaid', function ($answer, array $context) {
            $given = app(OrderCards::class)->refund($context['order'], (int) $context['amount'], $context['source'] ?? null);

            return $given > 0
                ? __(':amount went back onto the gift card.', ['amount' => format_price($given, $context['order']->currency_code)])
                : $answer;
        });

        // A cancelled order gives the card its money back by itself — the
        // money a customer never got anything for must not sit on an order
        // nobody will ship (the unpaid sweep cancels such orders too).
        hook_listen('checkout.order_status_changed', function (array $context) {
            $order = $context['order'];

            // Named statuses, never "any that returns stock": `backordered`
            // returns stock too, and a paid order waiting for a delivery must
            // not lose its payment.
            if (! in_array($context['status']->code ?? null, (array) setting('torgash.giftcards', 'return_statuses', []), true)
                || (int) $order->prepaid_total <= 0) {
                return;
            }

            app(Refund::class)->refund($order, (int) $order->prepaid_total, __('Order cancelled'), toPrepaid: (int) $order->prepaid_total);
        });

        // Issued once the order is PAID, whichever door the money came in by.
        $issue = function (array $context) {
            $order = $context['order'] ?? null;

            if (! $order instanceof Order) {
                return;
            }

            $order->refresh();

            if ($order->payment_state === PaymentState::PAID) {
                app(Delivery::class)->afterSale($order, app(Issuer::class)->fromOrder($order));
            } elseif ($order->payment_state === PaymentState::REFUNDED && setting('torgash.giftcards', 'void_on_refund', true)) {
                app(Expiry::class)->voidSoldBy($order);
            }
        };

        hook_listen('checkout.order_placed', $issue);
        hook_listen('checkout.order.changed', $issue);

        // The order screen: which cards paid, putting one on or taking it off.
        hook_listen('checkout.order.payment_extra', function (string $html, array $context) {
            if (! auth()->user()?->can('giftcards.manage')) {
                return $html;
            }

            $order = $context['order'];
            $paid = app(OrderCards::class)->paid($order);

            return $html.view('torgash.giftcards::admin.order-panel', [
                'order' => $order,
                'paidCards' => Card::whereIn('id', array_keys($paid) ?: [0])->get()->map(fn (Card $card) => [
                    'card' => $card,
                    'amount' => $paid[$card->id],
                ]),
                'soldCards' => Card::where('order_id', $order->id)->orderBy('id')->get(),
            ])->render();
        });
    }

    /** A gift card as a catalogue product. */
    protected function selling(): void
    {
        hook_listen('catalog.products.listed', fn (array $context) => app(GiftConfig::class)->prime($context['products'] ?? []));

        hook_listen('cart.line.config', function (array $config, array $context) {
            $product = $context['product'] ?? null;

            return $product instanceof Product ? app(GiftConfig::class)->normalise($product, $config) : $config;
        });

        hook_listen('cart.line.parts', function (array $parts, array $context) {
            $product = $context['product'] ?? null;

            return $product instanceof Product
                ? array_merge($parts, app(GiftConfig::class)->parts($product, (array) ($context['config'] ?? []), isset($context['base']) ? (int) $context['base'] : null))
                : $parts;
        });

        // A card whose amount is typed cannot be dropped into a basket from a
        // list: the list opens the choice window instead.
        // An electronic card is sent by letter, never by a courier: a basket
        // of cards is placed with no address and no delivery.
        hook_listen('catalog.product.needs_delivery', fn (bool $needs, array $context) => $needs
            && ! app(GiftConfig::class)->isGift($context['product'] ?? null));

        hook_listen('cart.product.asks', function (bool $asks, array $context) {
            $row = app(GiftConfig::class)->row($context['product'] ?? null);

            return $asks || ($row?->free_amount ?? false);
        });

        hook_listen('catalog.price.from', function (int $minimum, array $context) {
            $row = app(GiftConfig::class)->row($context['product'] ?? null);

            return $row && $row->free_amount ? max($minimum, $row->minimum()) : $minimum;
        });

        hook_listen('cart.buy_box.fields', function (string $html, array $context) {
            $product = $context['product'] ?? null;
            $row = app(GiftConfig::class)->row($product);

            if (! $row) {
                return $html;
            }

            return $html.view('torgash.giftcards::storefront.fields', [
                'giftRow' => $row,
                'giftAnswers' => (array) (((array) ($context['config'] ?? []))[GiftConfig::KEY] ?? []),
                'giftErrors' => (array) ($context['errors'] ?? []),
            ])->render();
        });

        // A card is money, not goods: never discounted by a coupon or a
        // promotion — a 10% coupon on a 1000 card is 100 of cash.
        hook_listen('checkout.coupon.base', function (int $base, array $context) {
            $cart = $context['cart'] ?? null;

            if (! $cart || ! method_exists($cart, 'items')) {
                return $base;
            }

            foreach ($cart->items() as $item) {
                if (app(GiftConfig::class)->isGift($item->product)) {
                    $base -= (int) $item->line_total;
                }
            }

            return max(0, $base);
        });

        hook_listen('promotions.cart_lines', fn (array $lines) => array_values(array_filter(
            $lines,
            fn ($line) => ! app(GiftConfig::class)->isGift((int) ($line['product_id'] ?? 0)),
        )));

        hook_listen('catalog.product.form_tabs', function (array $tabs, array $context) {
            $product = $context['product'] ?? null;

            if (! $product instanceof Product) {
                return $tabs;
            }

            $tabs[] = [
                'key' => 'giftcard',
                'section' => true,
                'label' => __('Gift card'),
                'html' => view('torgash.giftcards::admin.product-tab', [
                    'giftRow' => $product->exists ? Models\GiftProduct::find($product->id) : null,
                ])->render(),
            ];

            return $tabs;
        });

        hook_listen('catalog.product.saved', function (array $context) {
            $request = $context['request'] ?? null;

            // Only the product form carries the tab; an import, 1C or the API
            // saving a product must not make it stop being a gift card.
            if (! $request || ! $request->has('giftcard_form')) {
                return;
            }

            $product = $context['product'];

            if (! $request->boolean('giftcard.is')) {
                Models\GiftProduct::where('product_id', $product->id)->delete();

                return;
            }

            $minor = fn ($value) => is_numeric(str_replace(',', '.', (string) $value)) ? (int) round((float) str_replace(',', '.', (string) $value) * 100) : null;

            Models\GiftProduct::updateOrCreate(['product_id' => $product->id], [
                'free_amount' => $request->boolean('giftcard.free_amount'),
                'min_amount' => $minor($request->input('giftcard.min')),
                'max_amount' => $minor($request->input('giftcard.max')),
            ]);
        });
    }

    /** The customer's own cards, the card's page, the account menu. */
    protected function storefront(): void
    {
        hook_listen('core.account.nav', fn (array $items) => [...$items, [
            'label' => __('Gift cards'),
            'group' => 'bonuses',
            'url' => route('account.giftcards.index'),
            'icon' => 'gift',
            'active' => request()->routeIs('*account.giftcards.*'),
        ]]);
    }
}
