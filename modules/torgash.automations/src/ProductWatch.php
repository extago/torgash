<?php

namespace Torgash\Automations;

use Illuminate\Support\Facades\DB;
use Torgash\Automations\Models\ProductBatch;
use Torgash\Automations\Models\Rule;

/**
 * What happened to the shelf, said once per act (PLAN §64.2).
 *
 * Every movement of stock and every change of a price during one request or
 * one job is only REMEMBERED here; when the act is over the products are
 * looked at once, and those that crossed a line — ran low, ran out, came
 * back, changed their price — go to the rules together, as one
 * {@see ProductBatch} per event. So an import that empties three thousand
 * shelves is one message, and a number set and put back within one act is
 * no event at all.
 *
 * A CROSSING, never a level: "runs low" is the stock coming down through the
 * product's line (LowStock::thresholds — its own, the shop's, or what it
 * sells in N days), not every sale of something already low. Where the stock
 * stood before is the stock now less everything that moved — which holds for
 * a product kept on its own column and for one kept per warehouse alike.
 *
 * A singleton that holds one act's notes and is emptied by `flush()`; nothing
 * of it outlives the request.
 */
class ProductWatch
{
    public const LOW = 'automations.product.low';

    public const OUT = 'automations.product.out';

    public const RESTOCKED = 'automations.product.restocked';

    public const PRICE = 'automations.product.price_changed';

    /** @var array<int, int> product => everything its stock moved by */
    protected array $moved = [];

    /** @var array<int, array{0: int, 1: int}> product => [the price before the act, the price now] */
    protected array $prices = [];

    /** @var array<int, true> products that appeared in this act */
    protected array $born = [];

    /**
     * A product created in this act: its first stock is not a shelf filled
     * AGAIN and its first price is not a change — nothing of it is an event.
     */
    public function born(int $productId): void
    {
        $this->born[$productId] = true;
    }

    public function moved(int $productId, int $delta): void
    {
        $this->moved[$productId] = ($this->moved[$productId] ?? 0) + $delta;
    }

    public function repriced(int $productId, int $from, int $to): void
    {
        $this->prices[$productId] = [$this->prices[$productId][0] ?? $from, $to];
    }

    /** The act is over: say what crossed a line, to the rules that asked. */
    public function flush(): void
    {
        $moved = array_diff_key($this->moved, $this->born);
        $prices = array_diff_key($this->prices, $this->born);
        $this->moved = $this->prices = $this->born = [];

        if ($moved === [] && $prices === []) {
            return;
        }

        // Nobody asked — nothing is read, nothing is written.
        $wanted = Rule::whereIn('event', [self::LOW, self::OUT, self::RESTOCKED, self::PRICE])->where('enabled', true)
            ->distinct()->pluck('event')->all();

        if ($wanted === []) {
            return;
        }

        $events = [];

        if (array_intersect($wanted, [self::LOW, self::OUT, self::RESTOCKED]) !== [] && array_filter($moved) !== []) {
            $products = DB::table('m_catalog_products')->whereIn('id', array_keys(array_filter($moved)))->whereNull('deleted_at')
                // In one order every time: a list that reshuffles between two messages reads as a different list.
                ->orderBy('id')->get(['id', 'stock', 'low_stock_threshold']);
            $lines = LowStock::thresholds($products);

            foreach ($products as $product) {
                $now = (int) $product->stock;
                $before = $now - $moved[(int) $product->id];
                $line = $lines[(int) $product->id] ?? 0;
                $item = ['id' => (int) $product->id, 'before' => $before, 'after' => $now];

                if ($before <= 0 && $now > 0) {
                    $events[self::RESTOCKED][] = $item;
                } elseif ($before > 0 && $now <= 0) {
                    $events[self::OUT][] = $item;
                } elseif ($now < $before && $now > 0 && $now <= $line && $before > $line) {
                    $events[self::LOW][] = $item;
                }
            }
        }

        foreach ($prices as $id => [$from, $to]) {
            if ($from !== $to) {
                $events[self::PRICE][] = ['id' => $id, 'before' => $from, 'after' => $to];
            }
        }

        foreach ($events as $event => $items) {
            if (in_array($event, $wanted, true)) {
                hook_action('automations.product.batch', ['batch' => ProductBatch::create(['event' => $event, 'items' => $items])]);
            }
        }
    }
}
