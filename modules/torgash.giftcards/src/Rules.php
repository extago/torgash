<?php

namespace Torgash\Giftcards;

use Torgash\Catalog\Support\CategoryTree;
use Torgash\Giftcards\Models\Card;

/**
 * How much of a bill ONE card may pay (PLAN §40.4) — the half older engines miss:
 * where a card may be spent and how much of an order it may close.
 *
 * A card's rules, all optional (none = everywhere, all of it):
 *   categories   — only goods in these categories, WITH their subtrees
 *                  (a category is its subcategories everywhere in the engine);
 *   shipping     — may it pay for delivery (the shop's setting by default);
 *   max_percent  — at most this share of the order;
 *   min_order    — only on an order of at least this much.
 *
 * Asked about LINES — `[{product_id, categories?, line_total}]` — plus the
 * bill's total and its delivery, so the cart and a placed order ask the same
 * question the same way. A line that cannot be paid for is taken out at its
 * full price, never its discounted one: when in doubt the card pays a little
 * LESS, and the customer pays the difference the ordinary way, which is the
 * direction nobody complains about.
 */
class Rules
{
    public function __construct(protected GiftConfig $gifts) {}

    /**
     * @param  list<array{product_id: int, line_total: int, category_ids?: list<int>}>  $lines
     */
    public function payable(Card $card, array $lines, int $total, int $shipping): int
    {
        $total = max(0, $total);
        $minOrder = (int) $card->rule('min_order', 0);

        if ($total <= 0 || ($minOrder > 0 && $total < $minOrder)) {
            return 0;
        }

        $base = $total;

        if (! (bool) $card->rule('shipping', setting('torgash.giftcards', 'pays_shipping', true))) {
            $base -= max(0, $shipping);
        }

        $allowed = $this->allowedCategories($card);
        $cardsBuyCards = (bool) setting('torgash.giftcards', 'cards_buy_cards', false);

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $outside = (! $cardsBuyCards && $this->gifts->isGift($productId))
                || ($allowed !== null && array_intersect($line['category_ids'] ?? $this->categoriesOf($productId), $allowed) === []);

            if ($outside) {
                $base -= max(0, (int) ($line['line_total'] ?? 0));
            }
        }

        $base = max(0, $base);
        $percent = (int) $card->rule('max_percent', 0);

        if ($percent > 0 && $percent < 100) {
            $base = min($base, intdiv($total * $percent, 100));
        }

        return $base;
    }

    /** @return list<int>|null the categories it may pay in, subtrees included; null = anywhere */
    protected function allowedCategories(Card $card): ?array
    {
        $ids = array_values(array_filter(array_map('intval', (array) $card->rule('categories', []))));

        return $ids === [] ? null : app(CategoryTree::class)->descendants($ids);
    }

    /** @return list<int> */
    protected function categoriesOf(int $productId): array
    {
        return \Illuminate\Support\Facades\DB::table('m_catalog_category_product')
            ->where('product_id', $productId)->pluck('category_id')->map('intval')->all();
    }

    /** A plain sentence per rule, for the admin and the card's own page. */
    public function describe(Card $card): array
    {
        $said = [];

        if ($ids = (array) $card->rule('categories', [])) {
            $names = \Torgash\Catalog\Models\Category::whereIn('id', $ids)->get()->map(fn ($c) => $c->t('name'))->implode(', ');
            $said[] = __('Only for goods in: :categories', ['categories' => $names]);
        }

        if ($card->rule('shipping') === false) {
            $said[] = __('Does not pay for delivery');
        }

        if (($percent = (int) $card->rule('max_percent', 0)) > 0 && $percent < 100) {
            $said[] = __('Pays up to :percent% of an order', ['percent' => $percent]);
        }

        if (($min = (int) $card->rule('min_order', 0)) > 0) {
            $said[] = __('On an order of at least :amount', ['amount' => format_price($min)]);
        }

        return $said;
    }
}
