<?php

namespace Torgash\Promotions;

use App\Core\Storefronts\StorefrontRoutes;
use App\Models\Storefront;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Orders\OrderDiscounts;
use Torgash\Checkout\Orders\OrderStock;
use Torgash\Promotions\Models\Promotion;
use Torgash\Promotions\Models\PromotionTier;

class PromotionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PromotionCache::class);
        // Read again by every queue job — a sale started in the admin must
        // reach the next export or letter a long-running worker handles.
        \App\Core\Support\PerRequest::flushPerJob($this->app, PromotionCache::class);
    }

    public function boot(): void
    {
        // A sale starts, ends and opens its weekly window with nothing
        // written: a page kept ready for guests is kept only until the next
        // of those moments (PLAN §61). Which order took which sale is on
        // nobody's page.
        page_cache()
            ->changes(fn () => Models\Promotion::nextChange())
            ->unseen('m_promotions_order_promotions');

        // Its part of the demo shop (PLAN §55.3), after the catalogue's products.
        hook_listen('core.demo.parts', fn (array $parts) => $parts + [
            'promotions' => ['label' => 'Promotions', 'after' => ['catalog'], 'install' => fn (\App\Core\Demo\DemoPack $pack) => app(Demo\PromotionsDemo::class)->install($pack)],
        ]);

        $this->catalogZone();

        // A menu item can point at this page by name (torgash.menus, PLAN §39).
        hook_listen('menus.pages', fn (array $pages) => $pages + [
            'route:promotions.index' => ['label' => 'Sale', 'route' => 'promotions.index', 'icon' => 'badge-percent'],
        ]);
        $this->cartZone();

        // Answered lazily, and a harmless no-op when torgash.api is not
        // installed: the closure only runs if that module ever asks.
        hook_listen('api.resources', fn () => (new Api\PromotionResources)->register());

        // Values of a characteristic merged into one (catalog, PLAN §60): a
        // tier asking for "темно-синий" asks for "тёмно‑синий" now.
        hook_listen('catalog.feature_value.merged', function (array $context) {
            $featureId = (int) $context['feature']->id;
            $into = (int) $context['into']->id;
            $merged = array_map('intval', $context['merged']);

            foreach (PromotionTier::query()->whereRaw("conditions::text like '%feature%'")->orderBy('id')->get() as $tier) {
                $conditions = (array) $tier->conditions;
                $changed = false;

                foreach ((array) ($conditions['rules'] ?? []) as $i => $rule) {
                    if (($rule['kind'] ?? null) === 'feature'
                        && (int) ($rule['feature_id'] ?? 0) === $featureId
                        && in_array((int) ($rule['value'] ?? 0), $merged, true)) {
                        $conditions['rules'][$i]['value'] = $into;
                        $changed = true;
                    }
                }

                if ($changed) {
                    $tier->conditions = $conditions;
                    $tier->save();
                }
            }
        });

        // What must be true about a promotion, for whoever is watching. A filter
        // nobody fires when `torgash.guard` is not installed, so this is a
        // statement about our own data rather than a dependency on theirs.
        hook_listen('guard.invariants', fn (array $all) => $all
            + (new Invariants\PromotionInvariants)->register());

        // Whichever changes first: an admin's save touches the Promotion row
        // (name/zone/combine/...), a tier rewrite (see saveTiers()) touches
        // PromotionTier. Either way the cached "what's running" list is stale.
        Promotion::saved(fn () => app(PromotionCache::class)->flush());
        Promotion::deleted(fn () => app(PromotionCache::class)->flush());
        PromotionTier::saved(fn () => app(PromotionCache::class)->flush());
        PromotionTier::deleted(fn () => app(PromotionCache::class)->flush());

        Route::middleware(['web', 'auth', 'can:promotions.manage'])
            ->prefix('admin/promotions')
            ->name('admin.promotions.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // The sale's own pages. Registered once per locale shape and never
        // from the current request — the rule that keeps route:cache possible.
        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        $this->storefront();

        $this->blocks();
    }

    /**
     * Two blocks for a page of the shop's own arranging (torgash.blocks, when
     * it is installed — a filter nobody asks otherwise): the sale with a
     * countdown beside its products, and the current sales as cards. Which
     * sale and until when is Showcase's, i.e. the model's — never the block's.
     * Neither has a background of its own: colour and room are the SECTION's
     * (set on the zone screen), and a panel inside a band is two of them.
     */
    protected function blocks(): void
    {
        if (! class_exists(\Torgash\Blocks\BlockTypes::class)) {
            return;
        }

        $catalogPromotions = fn () => [
            ['value' => '', 'label' => 'The one that ends soonest'],
            ...Promotion::where('zone', Promotion::ZONE_CATALOG)->orderBy('id')->get()
                ->map(fn (Promotion $promotion) => ['value' => (string) $promotion->id, 'label' => $promotion->t('name')])->all(),
        ];

        \Torgash\Blocks\BlockTypes::register('promotion_deal', [
            'description' => 'A sale with a countdown to its end beside a row of its products — the deal of the day.',
            'fits' => ['wide'],
            'label' => 'Deal with a countdown',
            'icon' => 'timer',
            'view' => 'torgash.promotions::blocks.deal',
            'schema' => [
                ['key' => 'promotion', 'type' => 'select', 'default' => '', 'label' => 'Promotion', 'options' => $catalogPromotions,
                    'help' => 'Shown only while it runs and has products in view. "Ends soonest" picks among the running sales whichever closes first, so daily windows take turns.'],
                ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading',
                    'help' => 'Empty — the name of the promotion.'],
                ['key' => 'text', 'type' => 'textarea', 'translatable' => true, 'label' => 'Text',
                    'help' => 'Empty — the first lines of its description.'],
                ['key' => 'limit', 'type' => 'number', 'default' => 10, 'min' => 2, 'max' => 24, 'label' => 'How many products'],
                ['key' => 'per_row', 'type' => 'select', 'default' => '4', 'label' => 'In a row on a computer', 'options' => [
                    ['value' => '3', 'label' => '3'], ['value' => '4', 'label' => '4'], ['value' => '5', 'label' => '5'],
                ]],
                ...\Torgash\Catalog\Selections\ProductBlock::arrowSettings(),
            ],
        ]);

        \Torgash\Blocks\BlockTypes::register('promotion_cards', [
            'description' => 'The current sales as cards — a picture or the discount, the name and how long is left.',
            'fits' => ['wide'],
            'label' => 'Current promotions',
            'icon' => 'badge-percent',
            'view' => 'torgash.promotions::blocks.cards',
            'schema' => [
                ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading', 'help' => 'Optional.'],
                ['key' => 'limit', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 20, 'label' => 'How many promotions',
                    'help' => 'Those with a page on the storefront: running first, then the ones about to open. What is over is never shown.'],
                ['key' => 'per_row', 'type' => 'select', 'default' => '5', 'label' => 'In a row on a computer', 'options' => [
                    ['value' => '3', 'label' => '3'], ['value' => '4', 'label' => '4'], ['value' => '5', 'label' => '5'],
                ]],
                ['key' => 'recurring', 'type' => 'checkbox', 'default' => true, 'label' => 'Sales on a schedule too',
                    'help' => 'Those that repeat — every day, at weekends. Off: this block leaves them out (a "Deal with a countdown" may already be showing one); their pages and the list of all sales keep them.'],
            ],
        ]);
    }

    /**
     * The storefront's half: the note under a product's price, and the sale
     * pages in the sitemap.
     *
     * Both hang off hooks catalog already publishes, so nothing here is a
     * patch of another module — the note goes through the same
     * `catalog.product.price_extra` filter that exists for "a price valid
     * until" lines, and the sitemap answers a filter added beside it.
     */
    protected function storefront(): void
    {
        hook_listen('catalog.product.price_extra', function (string $html, array $context) {
            $promotions = app(CatalogPricing::class)->matching($context['product'], auth()->user());

            return $promotions === []
                ? $html
                : $html.view('torgash.promotions::partials.product-note', ['promotions' => $promotions])->render();
        });

        // The card's small timer among its labels: the
        // soonest end among the running sales this product is in that show a
        // countdown. Only those are asked about at all, so a shop with no
        // timed sale pays nothing per card.
        hook_listen('catalog.product.card_flags', function (string $html, array $context) {
            if (! setting('torgash.promotions', 'card_countdown')) {
                return $html;
            }

            $until = app(CatalogPricing::class)->countdownFor($context['product'], auth()->user());

            return $until
                ? $html.view('torgash.promotions::partials.countdown', ['until' => $until, 'countdownPill' => true])->render()
                : $html;
        });

        // A sale as the source of a product block ("the deal of the day, on
        // the home page"). Only while it is actually running and applies here:
        // a block still showing last week's sale under its name is advertising
        // prices nobody will be charged.
        hook_listen('catalog.product_sources', fn (array $sources) => $sources + [
            'promotion' => [
                'label' => 'Promotion',
                'choices' => fn () => Promotion::where('zone', Promotion::ZONE_CATALOG)->orderBy('id')->get()
                    ->mapWithKeys(fn (Promotion $promotion) => [$promotion->id => $promotion->t('name')])
                    ->all(),
                'apply' => function (Builder $query, string $arg) {
                    $promotion = Promotion::with('tiers')->find((int) $arg);

                    return $promotion && $promotion->isRunning() && $promotion->appliesIn()
                        ? $promotion->constrainProducts($query, auth()->user())
                        : $query->whereRaw('1 = 0');
                },
                'heading' => fn (string $arg) => Promotion::find((int) $arg)?->t('name'),
                'url' => function (string $arg) {
                    $promotion = Promotion::published()->find((int) $arg);

                    return $promotion ? route('promotions.show', $promotion->slug) : null;
                },
            ],
        ]);

        hook_listen('catalog.sitemap.urls', function (array $urls) {
            $sales = Promotion::published()->where('enabled', true)->get()
                // A sale scoped to another storefront has no page HERE (its
                // own controller 404s it), and a sitemap that lists a 404 is
                // worse than one that lists nothing.
                ->filter(fn (Promotion $promotion) => $promotion->appliesIn() && $promotion->status() !== 'ended');

            if ($sales->isEmpty()) {
                return $urls;
            }

            $urls[] = ['loc' => route('promotions.index'), 'changefreq' => 'daily', 'priority' => '0.6'];

            foreach ($sales as $sale) {
                $urls[] = [
                    'loc' => route('promotions.show', $sale->slug),
                    'lastmod' => $sale->updated_at?->toAtomString(),
                    'changefreq' => 'daily',
                    'priority' => '0.6',
                ];
            }

            return $urls;
        });
    }

    /**
     * The two hooks Product::cheapestPriceCandidate() exposes — resolved
     * inside the closure rather than injected at boot, so a persistent worker
     * never answers from a memo built for a previous visitor (same rule as
     * every other per-request singleton in this codebase).
     */
    protected function catalogZone(): void
    {
        hook_listen('catalog.product.price_candidates', function (array $candidates, array $context) {
            return array_merge($candidates, app(CatalogPricing::class)->candidates(
                $context['product'], $context['user'], $context['qty'],
            ));
        });

        hook_listen('catalog.product.price_adjust', function (array $best, array $context) {
            return app(CatalogPricing::class)->adjust(
                $best, $context['product'], $context['user'], $context['qty'],
            );
        });

        // The catalogue's "on sale" tick box asks every pricing module how a
        // price can be cut, and this is one of the two answers (the other is
        // the catalogue's own RRP). One branch of one filter rather than a
        // second box beside it: a visitor asking for the discounts is asking
        // one question, and which mechanism produced a discount is the shop's
        // business, not theirs.
        hook_listen('catalog.filters.discounted', fn (array $branches) => [...$branches,
            fn (Builder $query) => app(CatalogPricing::class)->discounted($query),
        ]);
    }

    /**
     * A live cart re-evaluates on every request (rates and rules may have
     * changed since the last page load — same as taxes' cart half). A PLACED
     * order does not: it reads back the snapshot frozen at `order_placed` /
     * `order.recalculated`, exactly like torgash.taxes' own m_taxes_order_taxes
     * — a promotion edited or deleted next month must not silently move the
     * total of an order the customer already agreed to.
     *
     * once_per_customer is only ever checked by a guest's customer_ref once an
     * order — and therefore a customer_ref — exists (see Promotion::usableBy).
     * A live cart has no order yet, so a logged-in visitor is checked by
     * user_id here and a guest is checked for real only at placement; a guest
     * could in principle see a once-per-customer promotion in the cart preview
     * that then does not apply once the order is placed. Accepted for v1 —
     * the alternative is asking for contact details before a price can be
     * shown, which is worse.
     */
    protected function cartZone(): void
    {
        hook_listen('checkout.extra_totals', function (array $lines, array $context) {
            $cartLines = $this->basketLines($context['cart']->items());

            // Only the printed shape: `target`/`scope` are for laying a cut over
            // a placed order's lines and mean nothing to a basket's summary.
            return array_merge($lines, array_map(fn (array $line) => array_intersect_key($line, array_flip(['code', 'label', 'amount', 'included'])), app(CartPricing::class)->lines(
                $cartLines,
                (int) $context['subtotal'],
                (int) $context['shipping_cost'],
                auth()->user(),
                null,
                $context['payment_method_id'] ?? null,
                // What the coupon has already taken. Checkout hands it over on
                // the same hook, so a promotion discounts what the customer
                // still owes rather than the pre-coupon subtotal.
                (int) ($context['discount'] ?? 0),
            )));
        });

        // The only place a promotion's free-gift line can be added: it needs
        // to go through the SAME stock deduction as everything else, which
        // only happens once, inside PlaceOrder, before the order exists.
        hook_listen('checkout.order.items', function (array $items, array $context) {
            $cartLines = $this->basketLines($context['cart']->items());
            $subtotal = array_sum(array_column($cartLines, 'line_total'));

            $effects = app(CartPricing::class)->sideEffects($cartLines, $subtotal, $context['user']);

            foreach ($effects as ['promotion' => $promotion, 'tier' => $tier]) {
                if ($tier->discount_type !== PromotionTier::FREE_PRODUCT || ! $tier->free_product_id) {
                    continue;
                }

                $product = Product::find($tier->free_product_id);
                if (! $product) {
                    continue;
                }

                $gift = [
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'name' => $product->t('name'),
                    'qty' => $tier->free_qty,
                    'unit_price' => 0,
                    'line_total' => 0,
                    // Read back by snapshot() to record what the order was
                    // actually given — never re-matched from scratch, because
                    // the answer can differ from this one (see below).
                    'gift_promotion_id' => $promotion->id,
                ];

                // A gift is a bonus, not what the customer came for, so an
                // empty shelf silently withholds it: adding the line anyway
                // drove the product's stock to -1 (measured), and refusing the
                // whole order — or dropping it into `backordered` — would
                // punish a paying customer because a freebie ran out.
                //
                // Checked against the order AS IT STANDS, gifts already added
                // included: canCover() sums demand per product, which is what
                // answers the case where the gift is the very thing the
                // customer is buying the last of.
                if (! app(OrderStock::class)->canCover([...$items, $gift])) {
                    continue;
                }

                $items[] = $gift;
            }

            return $items;
        });

        hook_listen('checkout.order_placed', function (array $context) {
            /** @var Order $order */
            $order = $context['order'];

            $recorded = $this->snapshot($order);

            if ($recorded !== []) {
                // A bulk query-builder increment, not an Eloquent instance
                // save — it never fires Promotion::saved(), so the cache that
                // usage_limit/once_per_customer just read (activeInZone(),
                // still warm from computing $recorded above) has to be told
                // by hand or the very next cart on this same request would see
                // the pre-increment count.
                Promotion::whereIn('id', $recorded)->increment('used_count');
                app(PromotionCache::class)->flush();
            }
        });

        // An edit of a placed order does NOT ask today's promotions again
        // (PLAN §40.10/3): what was granted stays on the lines it was granted
        // for, and only what left the order takes its share with it. Asking
        // again is a manager's act — "Recheck the promotions" — which says so
        // through reprice() before recalculating.
        hook_listen('checkout.order.recalculated', function (array $context) {
            /** @var Order $order */
            $order = $context['order'];

            if (isset($this->reprice[$order->id])) {
                unset($this->reprice[$order->id]);
                DB::table('m_promotions_order_promotions')->where('order_id', $order->id)->delete();
                $this->snapshot($order);

                return;
            }

            $this->rescale($order);
        });

        // Merging (PLAN §40.10/4): B's promotions go with B's lines — the rows
        // move to A and their shares follow the lines' new keys, so an edit of
        // A later moves them exactly as it would have moved them on B.
        hook_listen('checkout.order.merging', function (array $context) {
            // A free delivery stays with B: it was B's delivery, and B's
            // delivery is gone — there is one parcel now, A's.
            $rows = DB::table('m_promotions_order_promotions')->where('order_id', $context['from']->id)
                ->where(fn ($q) => $q->whereNull('target')->orWhere('target', '!=', 'shipping'))
                ->get();

            foreach ($rows as $row) {
                DB::table('m_promotions_order_promotions')->where('id', $row->id)->update([
                    'order_id' => $context['into']->id,
                    'shares' => $row->shares === null ? null : json_encode((object) $this->renameShares((array) json_decode($row->shares, true), $context['keys'])),
                ]);
            }

            $context['merge']->remember('torgash.promotions', $rows->pluck('id')->all());
        });

        hook_listen('checkout.order.unmerging', function (array $context) {
            $back = array_flip(array_map('strval', $context['keys']));

            foreach ((array) $context['merge']->remembered('torgash.promotions') as $id) {
                $row = DB::table('m_promotions_order_promotions')->where('id', $id)->first();
                if ($row) {
                    DB::table('m_promotions_order_promotions')->where('id', $id)->update([
                        'order_id' => $context['from']->id,
                        'shares' => $row->shares === null ? null : json_encode((object) $this->renameShares((array) json_decode($row->shares, true), $back)),
                    ]);
                }
            }
        });

        // "Recheck the promotions" on a placed order (PLAN §40.6/4): the
        // difference is shown BEFORE it is applied — silently rewriting the
        // sum a customer agreed to is worse than the old number.
        hook_listen('checkout.order.discount_tools', function (string $html, array $context) {
            if (! auth()->user()?->can('promotions.manage')) {
                return $html;
            }

            return $html.view('torgash.promotions::admin.order-recheck', ['order' => $context['order']])->render();
        });

        // A page of orders reads its promotion lines in one query (OrderRows).
        $moneyOnly = fn ($query) => $query->where('amount', '!=', 0);
        hook_listen('checkout.orders.listed', fn (array $context) => app(\Torgash\Checkout\Support\OrderRows::class)
            ->prime('m_promotions_order_promotions', $context['orders']->pluck('id')->all(), $moneyOnly));

        hook_listen('checkout.order.extra_totals', function (array $lines, array $context) use ($moneyOnly) {
            /** @var Order $order */
            $order = $context['order'];

            $rows = app(\Torgash\Checkout\Support\OrderRows::class)->for('m_promotions_order_promotions', (int) $order->id, $moneyOnly);

            foreach ($rows as $row) {
                $lines[] = [
                    'code' => 'promotion:'.($row->promotion_id ?? $row->id),
                    'label' => $row->name,
                    'amount' => (int) $row->amount,
                    'included' => false,
                ];
            }

            return $lines;
        });
    }

    /**
     * Writes this order's promotion rows — discount lines with their amount,
     * plus a zero-amount row for a give_group/free_product tier that won
     * purely for the record (a CRM asking "what did this order get" and
     * Promotion::usableBy()'s once_per_customer check both read this table).
     *
     * @return list<int> promotion ids that were actually recorded, for the
     *                   order_placed listener to charge against usage_limit/used_count —
     *                   called again by order.recalculated, which must NOT re-charge usage
     *                   for a promotion the order was already given at placement.
     */
    /**
     * What today's promotions would take off this order, without writing it
     * down — the snapshot's own arithmetic, so "recheck" previews exactly
     * what applying will record.
     *
     * @return list<array{code: string, label: string, amount: int}>
     */
    public function pricedLines(Order $order): array
    {
        return app(CartPricing::class)->lines(
            $this->orderLines($order), (int) $order->subtotal, (int) $order->shipping_cost, $order->user,
            $order->customer_ref, $order->payment_method_id ? (string) $order->payment_method_id : null,
            (int) $order->discount_amount, $this->placeOf($order),
        );
    }

    /** @var array<int, true> orders whose next recalculation asks today's promotions */
    protected array $reprice = [];

    /**
     * The next recalculation of this order prices it by today's promotions,
     * over everything it holds — lines added since placement included.
     */
    public function reprice(Order $order): void
    {
        $this->reprice[$order->id] = true;
    }

    /**
     * Each granted promotion, moved only by what left the order: a line's
     * share follows its quantity DOWN (never up — units added later were not
     * part of the deal) and never exceeds what the line costs now; a free
     * delivery covers at most what the delivery costs now. The shares stay
     * as they were granted, so putting a unit back gives its part back. Then
     * the whole is capped by the goods left after the coupon, in the order the
     * promotions were granted. A row written before shares existed keeps its
     * sum, capped the same way.
     */
    protected function rescale(Order $order): void
    {
        $items = [];
        foreach ((array) $order->items as $item) {
            $key = OrderDiscounts::keyOf($item);
            $items[$key]['qty'] = ($items[$key]['qty'] ?? 0) + (int) ($item['qty'] ?? 0);
            $items[$key]['total'] = ($items[$key]['total'] ?? 0) + (int) ($item['line_total'] ?? 0);
        }

        $goodsLeft = max(0, (int) $order->subtotal - (int) $order->discount_amount);
        $shippingLeft = max(0, (int) $order->shipping_cost);

        $rows = DB::table('m_promotions_order_promotions')->where('order_id', $order->id)->orderBy('id')->get();

        foreach ($rows as $row) {
            $was = -(int) $row->amount;
            if ($was <= 0 && $row->shares === null) {
                continue; // a record of a gift or a group, not money
            }

            if ($row->target === 'shipping') {
                $cut = min($this->grantedTotal($row), $shippingLeft);
                $shippingLeft -= $cut;
            } else {
                $cut = $row->shares === null ? $was : 0;
                foreach ((array) json_decode((string) $row->shares, true) as $key => $share) {
                    $now = $items[(string) $key] ?? null;
                    $agreed = (int) ($share['q'] ?? 0);
                    if ($now === null || $now['qty'] <= 0 || $agreed <= 0) {
                        continue;
                    }
                    $part = intdiv((int) $share['a'] * min($now['qty'], $agreed), $agreed);
                    $cut += min($part, $now['total']);
                }
                $cut = min($cut, $goodsLeft);
                $goodsLeft -= $cut;
            }

            if ($cut !== $was) {
                DB::table('m_promotions_order_promotions')->where('id', $row->id)->update(['amount' => -$cut]);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $shares
     * @param  array<string, string>  $keys  old line key => new
     * @return array<string, mixed>
     */
    protected function renameShares(array $shares, array $keys): array
    {
        $out = [];
        foreach ($shares as $key => $share) {
            $out[$keys[(string) $key] ?? (string) $key] = $share;
        }

        return $out;
    }

    /** What a row was granted in full, whatever it has come to since. */
    protected function grantedTotal(object $row): int
    {
        $shares = (array) json_decode((string) $row->shares, true);

        return $shares === [] ? -(int) $row->amount : (int) array_sum(array_column($shares, 'a'));
    }

    protected function snapshot(Order $order): array
    {
        $orderLines = $this->orderLines($order);
        $subtotal = (int) $order->subtotal;
        $customerRef = $order->customer_ref;

        $paymentMethodId = $order->payment_method_id ? (string) $order->payment_method_id : null;

        // Where this ORDER is, not where the reader is. Re-pricing happens in
        // the admin too, where the current storefront is nobody's — and a rule
        // with no storefront to judge by fails closed, so without this a
        // manager opening an order would strip a storefront-scoped discount off
        // it just by saving.
        $where = $this->placeOf($order);

        $discountLines = $this->pricedLines($order);

        $recorded = [];

        $quantities = OrderDiscounts::quantities((array) $order->items);

        foreach ($discountLines as $line) {
            $promotionId = (int) str_replace('promotion:', '', $line['code']);
            $target = $line['target'] ?? 'goods';
            if ($target === 'shipping') {
                $shares = ['delivery' => ['a' => -(int) $line['amount'], 'q' => 0]];
            } else {
                $shares = [];
                foreach (app(CartPricing::class)->shares(-(int) $line['amount'], $orderLines, $line['scope'] ?? null) as $key => $amount) {
                    $shares[$key] = ['a' => $amount, 'q' => $quantities[(string) $key] ?? 0];
                }
            }

            DB::table('m_promotions_order_promotions')->insert([
                'order_id' => $order->id,
                'promotion_id' => $promotionId,
                'name' => $line['label'],
                'amount' => $line['amount'],
                'target' => $target,
                'shares' => json_encode((object) $shares),
            ]);
            $recorded[] = $promotionId;
        }

        foreach (app(CartPricing::class)->sideEffects($orderLines, $subtotal, $order->user, $customerRef, $paymentMethodId, $where) as ['promotion' => $promotion, 'tier' => $tier]) {
            // A gift is recorded from what the ORDER actually carries, not from
            // re-deriving that it won: the items hook withholds a gift the
            // shelf could not cover, and a manager editing the order can strike
            // the line out. Re-matching here would write "gift given" over an
            // order that never got one — and charge a redemption for it.
            if ($tier->discount_type === PromotionTier::FREE_PRODUCT && ! $this->carriesGiftFrom($order, $promotion->id)) {
                continue;
            }

            DB::table('m_promotions_order_promotions')->insert([
                'order_id' => $order->id,
                'promotion_id' => $promotion->id,
                'name' => $promotion->t('name').' ('.$tier->discount_type.')',
                'amount' => 0,
            ]);
            $recorded[] = $promotion->id;

            // give_group's actual effect — an order has no group of its own
            // to carry it on, so it lands directly on the account. A guest
            // order never reaches here: usableBy()'s user-based branch only
            // matches a logged-in customer, and a guest has no group to
            // upgrade in the first place.
            if ($tier->discount_type === PromotionTier::GIVE_GROUP && $tier->give_group_id && $order->user) {
                $order->user->update(['customer_group_id' => $tier->give_group_id]);
            }
        }

        return $recorded;
    }

    /**
     * The display-rule context an ORDER answers for itself.
     *
     * Only the storefront today, because that is the only "where" an order
     * carries; the rest (locale, visitor) is left to the request, where a rule
     * about the reader — if a shop ever writes one on a promotion — would be
     * meaningless for a placed order anyway.
     *
     * @return array<string, mixed>
     */
    protected function placeOf(Order $order): array
    {
        return $order->storefront_id
            ? ['storefront' => Storefront::find($order->storefront_id)]
            : [];
    }

    /** Does this order carry a gift line this promotion put there? */
    protected function carriesGiftFrom(Order $order, int $promotionId): bool
    {
        foreach ((array) $order->items as $item) {
            if ((int) ($item['gift_promotion_id'] ?? 0) === $promotionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The cart as this module reads it — and the one place another module can
     * take a line OUT of what a promotion may discount.
     *
     * A line already carrying a discount of its own (a kit's, today) is not
     * discounted twice: that is the shop owner's own rule (PLAN §33.8), and
     * "the case is half price in the set AND ten per cent off because it is an
     * accessory" is how a shop ends up selling below cost. `discounted` is
     * stated here rather than guessed by the answerer — a line whose parts
     * take money OFF is discounted, whoever put that part there.
     *
     * @return list<array{product_id: int, category_ids: list<int>, qty: int, line_total: int, discounted: bool}>
     */
    protected function basketLines(Collection $items): array
    {
        $productIds = $items->pluck('product.id')->all();
        $categoriesByProduct = Product::whereIn('id', $productIds)
            ->with('categories:id')
            ->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => $p->categories->pluck('id')->map('intval')->all()]);

        $lines = $items->map(fn ($item) => [
            // The line's own name and what it is made of. Both are its PUBLIC
            // shape (cart states a line as `{key, product_id, qty, config}`
            // and prices it into parts), and neither is read here: they are
            // for a module that priced this line and has to recognise its own
            // work — see `config` below.
            'key' => $item->key,
            'parts' => (array) ($item->parts ?? []),
            'product_id' => $item->product->id,
            'category_ids' => $categoriesByProduct[$item->product->id] ?? [],
            'qty' => $item->qty,
            'line_total' => $item->line_total,
            'discounted' => collect($item->parts ?? [])->contains(fn (array $part) => $part['amount'] < 0),
            // The line's own configuration bag, passed through untouched and
            // never read here: it is the line's PUBLIC shape (cart states it
            // as `{key, product_id, qty, config}`), and a module that priced
            // this line needs it to recognise its own work. `discounted`
            // above only catches a discount written as a negative part, and
            // not every module writes one — a kit prices its parts below the
            // shelf price instead.
            'config' => (array) ($item->config ?? []),
        ])->all();

        return array_values(hook_filter('promotions.cart_lines', $lines, []));
    }

    /** @return list<array{product_id: int, category_ids: list<int>, qty: int, line_total: int}> */
    protected function orderLines(Order $order): array
    {
        $items = (array) $order->items;
        $productIds = array_column($items, 'product_id');

        $categoriesByProduct = Product::whereIn('id', $productIds)
            ->with('categories:id')
            ->get()
            ->mapWithKeys(fn (Product $p) => [$p->id => $p->categories->pluck('id')->map('intval')->all()]);

        $lines = array_map(fn (array $item) => [
            'key' => (string) ($item['key'] ?? $item['product_id'] ?? ''),
            // What it was bought as part of, when it was. An order has a line
            // per goods, so the pieces of a set are rows of their own here.
            'parent_key' => $item['parent_key'] ?? null,
            'product_id' => (int) ($item['product_id'] ?? 0),
            'category_ids' => $categoriesByProduct[(int) ($item['product_id'] ?? 0)] ?? [],
            'qty' => (int) ($item['qty'] ?? 0),
            'line_total' => (int) ($item['line_total'] ?? 0),
            // A placed order's line says the same thing in its own snapshot:
            // whatever was taken off it is listed there, negative.
            'discounted' => collect($item['options'] ?? [])->contains(fn (array $option) => (int) ($option['amount'] ?? 0) < 0),
            'config' => (array) ($item['config'] ?? []),
        ], $items);

        return array_values(hook_filter('promotions.cart_lines', $lines, ['order' => $order]));
    }
}
