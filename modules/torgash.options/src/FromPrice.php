<?php

namespace Torgash\Options;

use Torgash\Cart\Lines;
use Torgash\Catalog\Models\Product;
use Torgash\Options\Models\OptionChoice;
use Torgash\Options\Models\OptionGroup;

/**
 * The least a product can ACTUALLY be bought for (PLAN §33.7).
 *
 * A server that cannot leave the shop without a processor has no price — it
 * has a cheapest one, and printing the bare configuration's number on a card
 * quotes a sum nobody is able to pay. That is not a display nicety: a feed
 * quoting it is a price mismatch on every marketplace that checks, and a
 * customer who clicks through from one arrives at a dearer page.
 *
 * Only what is DEMANDED counts. An optional extended warranty does not raise
 * the "from" price, because the product really is buyable without it; a
 * required question does, and so does one that becomes required once the
 * cheapest answer to an earlier question has been given — which is why the
 * cheapest build is worked out by repeated passes through `Groups::asked()`
 * rather than in one sweep. A kit never raises it either, and never could:
 * a kit leaves three goods three goods and the main product is still on sale
 * by itself (§33.5).
 *
 * The build is then priced by exactly the code that will price the cart line —
 * `Configurator::parts()` + `Lines::money()` — so "from 45 000" and the total
 * the configurator shows once those same answers are picked cannot be two
 * different numbers. One unit, because a card quotes one.
 *
 * A registered per-request singleton, memoised by product AND base price: the
 * storefront asks about the visitor's own price and an export asks about
 * nobody's, and the two are genuinely different answers.
 */
class FromPrice
{
    /** How many times the cheapest build may reveal another required question. */
    protected const PASSES = 20;

    /** @var array<string, int> */
    protected array $memo = [];

    public function __construct(
        protected Groups $groups,
        protected Configurator $configurator,
        protected PriceRules $rules,
    ) {}

    /**
     * The cheapest price one of these can be bought at, in the shop's books.
     *
     * Equal to `$base` when the product demands nothing — which is the whole
     * catalogue of an ordinary shop, and the reason this costs a page nothing.
     */
    public function minimum(Product $product, int $base): int
    {
        $key = (int) $product->id.':'.$base;

        return $this->memo[$key] ??= $base + $this->addition($product, $base);
    }

    /** What the cheapest set of demanded answers adds to one unit. */
    public function addition(Product $product, int $base): int
    {
        $answers = $this->cheapestBuild($product, $base);

        if ($answers === []) {
            return 0;
        }

        $parts = $this->configurator->parts($product, 1, [Configurator::KEY => $answers], $base);
        $money = Lines::money($base, $parts, 1);

        // Both numbers, because one unit is charged both: what repeats per unit
        // and what is charged once for the line are the same money when the
        // line is one item, and a card quotes one item.
        return $money['unit_price'] + $money['extra_total'] - $base;
    }

    /**
     * The cheapest answers to everything this product insists on.
     *
     * Repeated passes, hiding nothing and only ever ADDING an answer, so it
     * settles: answering the processor may put a memory question on the page,
     * and that one may be required too. Bounded like every other walk here —
     * a dependency loop is a corrupt row, not an impossible one, and
     * `Groups::asked()` already takes circles out.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function cheapestBuild(Product $product, int $base): array
    {
        $answers = [];

        for ($pass = 0; $pass < self::PASSES; $pass++) {
            $added = false;

            foreach ($this->groups->asked($product, $answers) as $group) {
                $key = (string) $group->id;

                if (! $group->required || isset($answers[$key])) {
                    continue;
                }

                $entries = $this->cheapestEntries($group, $base);

                // A required question with nothing left on offer: the product
                // cannot be bought at all today, and inventing a part to price
                // it with would be a worse answer than its own price.
                if ($entries === []) {
                    continue;
                }

                $answers[$key] = $entries;
                $added = true;
            }

            if (! $added) {
                break;
            }
        }

        return $answers;
    }

    /**
     * The cheapest way to satisfy ONE question, in the canonical entry shape
     * `Configurator` reads.
     *
     * @return list<array<string, mixed>>
     */
    protected function cheapestEntries(OptionGroup $group, int $base): array
    {
        // What the customer writes costs whatever the group's price choice
        // costs, whatever they write — so the cheapest engraving is the only
        // engraving, and an empty string stands for the words not yet typed.
        if ($group->isTyped()) {
            $choice = $group->priceChoice();

            return $choice ? [['c' => (int) $choice->id, 'q' => 1, 't' => '']] : [];
        }

        $candidates = [];

        foreach ($group->choices as $choice) {
            if (! $choice->enabled) {
                continue;
            }

            $chosen = $this->answerProduct($choice);

            // A linked product that has gone, or a set with nothing buyable
            // left in it: `validate()` would refuse this answer, so it is not
            // one the shop is offering.
            if ($choice->kind !== 'plain' && ! $chosen) {
                continue;
            }

            $qty = max(1, (int) $group->min_qty);
            $entry = ['c' => (int) $choice->id, 'q' => $qty];

            if ($choice->isSet() && $chosen) {
                $entry['p'] = (int) $chosen->id;
            }

            $candidates[] = [
                'amount' => $this->rules->apply($choice, $base, $chosen) * $qty,
                'entry' => $entry,
            ];
        }

        if ($candidates === []) {
            return [];
        }

        usort($candidates, fn (array $a, array $b) => $a['amount'] <=> $b['amount']);

        // "Pick one" takes one; "tick at least three" takes the three cheapest.
        $wanted = $group->isSingle() ? 1 : max(1, (int) $group->min_select);

        return array_column(array_slice($candidates, 0, $wanted), 'entry');
    }

    /**
     * The product an answer would be about, asked of the choice alone: its own
     * for a `product` choice, the cheapest in the set for a `source` one.
     */
    protected function answerProduct(OptionChoice $choice): ?Product
    {
        if ($choice->isSet()) {
            return $this->configurator->cheapestInSource($choice);
        }

        $chosen = $this->configurator->productFor($choice, []);

        return $chosen?->isOrderable() ? $chosen : null;
    }

    /** For tests and for a screen that has just saved. */
    public function flush(): void
    {
        $this->memo = [];
    }
}
