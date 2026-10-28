<?php

namespace Torgash\Catalog\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Torgash\Catalog\Models\Product;

/**
 * The one place a product's popularity moves.
 *
 * Popularity is ONE number with two events behind it, and how much each is
 * worth is a shop setting (`popularity_view_points`, `popularity_order_points`,
 * 0 = that event does not count, both 0 = the number is entirely the admin's).
 * Points rather than tick boxes because a shop gets two orders and two hundred
 * views: weighted the same, views alone would decide the order and a product
 * everybody looks at and nobody buys would lead the listing. It is also how
 * older engines do it.
 *
 * An ORDER is worth its points once, whatever the quantity: this answers "how
 * often is it chosen", which is the question a shopper's "most popular" means.
 * `sales_count` still counts units beside it, unchanged, because the labels
 * read it and moving that goalpost would silently re-draw every "Hit" badge.
 * Nothing is taken back on a cancellation or a return — the same choice
 * WooCommerce makes with `total_sales`, and a decrement is a feature of its
 * own if the shop ever wants it.
 *
 * Written with a plain query rather than `$product->increment()` so the
 * product's `updated_at` does not move: somebody looking at a page is not an
 * edit of the product, and a column that changes on every view would make
 * "what changed since yesterday" useless to an export.
 */
class Popularity
{
    /**
     * Requests we do not award points for.
     *
     * A heuristic and nothing more — a crawler that wants to look like Chrome
     * can, and the cost of a miss is one point. It is here because the session
     * gate that makes `views_count` honest for people does nothing to a bot:
     * a crawler keeps no cookies, so every visit is a new session and every
     * page it takes would count as another view. `AgentLists` was not reused —
     * those two lists are about AI fetchers and deliberately do NOT name
     * Googlebot or YandexBot, which are most of this traffic.
     */
    protected const BOTS = '/bot|crawl|spider|slurp|search|fetch|monitor|preview|scan|curl|wget|python|headless/i';

    /**
     * A person has looked at a product page.
     *
     * Called from the same place, and under the same once-per-session guard,
     * as `views_count` — the two count the same event, and a second guard
     * would eventually disagree with the first. `views_count` itself keeps its
     * old behaviour, bots included: it is an existing statistic, and quietly
     * changing what it counts would move the thresholds of every label already
     * written against it.
     */
    public function viewed(Product|int $product, Request $request): void
    {
        $points = (int) setting('torgash.catalog', 'popularity_view_points', 1);

        if ($points <= 0 || $this->isBot($request)) {
            return;
        }

        $this->award([(int) ($product instanceof Product ? $product->id : $product)], $points);
    }

    /**
     * An order has been placed — one award per product on it, however many of
     * each were bought.
     *
     * Reads the order's own items rather than the cart: what was ordered is
     * what the order says, and a listener that looked anywhere else would
     * count nothing for an order placed by the API or in one click.
     *
     * @param  iterable<array<string, mixed>>  $items
     */
    public function ordered(iterable $items): void
    {
        $points = (int) setting('torgash.catalog', 'popularity_order_points', 10);

        if ($points <= 0) {
            return;
        }

        $ids = [];

        foreach ($items as $item) {
            if ($id = (int) ($item['product_id'] ?? 0)) {
                $ids[] = $id;
            }
        }

        $this->award(array_values(array_unique($ids)), $points);
    }

    /** @param list<int> $productIds */
    protected function award(array $productIds, int $points): void
    {
        if ($productIds === []) {
            return;
        }

        DB::table('m_catalog_products')->whereIn('id', $productIds)->increment('popularity', $points);
    }

    public function isBot(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        // No User-Agent at all is a script, not a browser. A person's browser
        // always sends one.
        return $agent === '' || preg_match(self::BOTS, $agent) === 1;
    }
}
