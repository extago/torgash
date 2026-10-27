<?php

namespace Torgash\Checkout\Orders;

use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Stock\StockMoves;
use Torgash\Catalog\Stock\AvailabilityStatuses;
use Torgash\Checkout\Models\Order;

/**
 * Keeping the warehouse honest while an admin edits a placed order.
 *
 * An order in a status whose inventory_action is "decrease" has already taken
 * its units out of stock (Order::stock_deducted). Editing its lines therefore
 * moves stock too: raising a quantity takes more, lowering it or deleting a
 * line puts some back, adding a line takes for the first time. Doing nothing
 * would quietly desynchronise the shop from its own warehouse.
 *
 * Everything goes through Product::adjustStock(), never a raw increment: with
 * torgash.warehouses installed that splits the change across warehouses in
 * priority order and hands back which one gave up how much. That breakdown is
 * stored on the order line, so a later restore credits exactly the warehouses
 * that were drained instead of guessing — the same contract
 * CheckoutController and OrdersController::updateStatus already follow. Here it
 * has to be maintained INCREMENTALLY, which is the only new part: a partial
 * restore takes a slice off the stored breakdown and leaves the rest.
 */
class OrderStock
{
    /**
     * Apply the difference between two sets of order lines, line by line.
     * Returns the new lines with their warehouse breakdowns brought up to date.
     *
     * A no-op when the order's status does not hold stock: nothing was taken,
     * so nothing is owed back.
     *
     * @param  list<array<string,mixed>>  $before
     * @param  list<array<string,mixed>>  $after
     * @return list<array<string,mixed>>
     */
    public function apply(Order $order, array $before, array $after): array
    {
        return app(StockMoves::class)->because($this->cause($order, 'order'), fn () => $this->applyNow($order, $before, $after));
    }

    protected function applyNow(Order $order, array $before, array $after): array
    {
        if (! $order->stock_deducted) {
            return array_values($after);
        }

        $beforeByLine = $this->byLine($before);
        $afterByLine = $this->byLine($after);

        foreach (array_keys($beforeByLine + $afterByLine) as $lineKey) {
            $old = $beforeByLine[$lineKey] ?? null;
            $new = $afterByLine[$lineKey] ?? null;

            $delta = (int) ($new['qty'] ?? 0) - (int) ($old['qty'] ?? 0);
            if ($delta === 0) {
                continue;
            }

            $product = Product::find((int) (($new['product_id'] ?? null) ?? ($old['product_id'] ?? 0)));
            if (! $product) {
                // The product was deleted after the order was placed. Its units
                // are not tracked anywhere any more; the line still edits.
                continue;
            }

            $breakdown = (array) ($old['warehouses'] ?? []);

            if ($delta > 0) {
                // More of a line the order already has: take it from where its
                // units came from, so an edit does not scatter one line across
                // warehouses that a consolidated order deliberately kept in one.
                $taken = $product->adjustStock(-$delta, $breakdown !== []
                    ? ['prefer' => (int) array_key_first($breakdown)]
                    : []);
                foreach ((array) $taken as $warehouseId => $amount) {
                    $breakdown[$warehouseId] = ($breakdown[$warehouseId] ?? 0) + $amount;
                }
            } else {
                $want = -$delta;
                $slice = $this->slice($breakdown, $want);
                $credited = array_sum(array_map(fn ($d) => -$d, $slice));

                if ($slice !== []) {
                    // The amount is passed as the delta too, not only as the
                    // restore: with the warehouses module gone since the order
                    // was placed the hook answers null, and adjustStock then
                    // falls back to a plain aggregate add of exactly this much.
                    $product->adjustStock($credited, ['restore' => $slice]);
                }

                if ($want > $credited) {
                    // Nothing recorded to credit — an order placed before the
                    // warehouses module existed, or a line that never had a
                    // breakdown. Aggregate add; the module picks a warehouse.
                    $product->adjustStock($want - $credited);
                }
            }

            if ($new !== null) {
                $afterByLine[$lineKey]['warehouses'] = $breakdown;
                if ($breakdown === []) {
                    unset($afterByLine[$lineKey]['warehouses']);
                }
            }
        }

        return array_values($afterByLine);
    }

    /**
     * Put units back that are physically coming back — a RETURN, not an edit.
     *
     * Distinct from apply(), and the difference matters: an edit changes what
     * the order says it sold, so its lines are rewritten and the warehouse
     * breakdown moves with them. A return changes nothing about what was sold —
     * the customer did buy it — so the order keeps its lines and its breakdown,
     * and the goods simply reappear on the shelf.
     *
     * They reappear WHERE THEY CAME FROM: the order line records which
     * warehouses gave up how many, so a return credits those rather than
     * whichever one happens to be first in the priority list. Lines with no
     * breakdown (placed before the warehouses module, or in a shop that has
     * none) fall back to an aggregate add, which is exactly what adjustStock
     * does when nothing answers the hook.
     *
     * Here rather than in torgash.rma because moving an order's stock is this
     * module's job — the returns module says WHAT is coming back, not how a
     * warehouse is credited. A no-op when the order never took the units.
     *
     * @param  list<array{product_id: int, qty: int}>  $lines  what is coming back
     * @param  int|null  $warehouseId  where the parcel actually arrived, when
     *                                 that is not where it left from. The owner's words about an exchange:
     *                                 "the quantity is added to the warehouse it came back TO" — a customer
     *                                 returns to the nearest shop, not to the one that shipped, and
     *                                 crediting the shipper would make both counts wrong at once. Null
     *                                 keeps the ordinary behaviour above.
     */
    public function restore(Order $order, array $lines, ?int $warehouseId = null): void
    {
        app(StockMoves::class)->because($this->cause($order, 'return'), fn () => $this->restoreNow($order, $lines, $warehouseId));
    }

    protected function restoreNow(Order $order, array $lines, ?int $warehouseId = null): void
    {
        if (! $order->stock_deducted) {
            return;
        }

        $sold = $this->byProductId($order->items ?? []);

        foreach ($this->byProductId($lines) as $productId => $line) {
            $product = Product::find($productId);
            $want = (int) $line['qty'];

            if (! $product || $want <= 0) {
                continue;
            }

            if ($warehouseId !== null) {
                // Named, so there is nothing to slice: every unit goes to the
                // one place. The `restore` map is how a credit names its
                // warehouses — negative values, because it carries deltas a
                // deduction applied and this undoes them.
                $product->adjustStock($want, ['restore' => [$warehouseId => -$want]]);

                continue;
            }

            // A copy: the order's own breakdown is left alone, because the order
            // still says it sold those units from those warehouses.
            $breakdown = (array) ($sold[$productId]['warehouses'] ?? []);
            $slice = $this->slice($breakdown, $want);
            $credited = array_sum(array_map(fn ($d) => -$d, $slice));

            if ($slice !== []) {
                $product->adjustStock($credited, ['restore' => $slice]);
            }

            if ($want > $credited) {
                $product->adjustStock($want - $credited);
            }
        }
    }

    /**
     * Take every unit of every line out of stock — an order moving INTO a
     * status that holds stock.
     *
     * @param  list<array<string,mixed>>  $items
     * @param  ?Order  $order  the order these lines belong to, when there is one
     *                         — it may itself name where the units must come from (an order that is
     *                         collected from a shop leaves that shop's shelf, not the nearest
     *                         warehouse), and only its own module can read that off it.
     * @return list<array<string,mixed>>
     */
    public function deductAll(array $items, ?Order $order = null): array
    {
        return app(StockMoves::class)->because($this->cause($order, 'order'), fn () => $this->deductAllNow($items, $order));
    }

    protected function deductAllNow(array $items, ?Order $order = null): array
    {
        $prefer = $this->consolidateTo($items, $order);

        foreach ($items as $i => $item) {
            $product = Product::find($item['product_id']);
            if (! $product) {
                continue;
            }

            if ($breakdown = $product->adjustStock(-$item['qty'], $prefer ? ['prefer' => $prefer] : [])) {
                $items[$i]['warehouses'] = $breakdown;
            }
        }

        return array_values($items);
    }

    /**
     * One warehouse that could send this whole order, asked of whoever owns
     * warehouses — or null, which is also the answer in a shop that has no such
     * module and the answer when nothing can cover it alone.
     *
     * Asked once for the order rather than per line, because the question is
     * about the order: filling it from three warehouses is three pickings and,
     * where they ship separately, three parcels. It is a PREFERENCE — a line the
     * chosen warehouse cannot cover still falls through to the priority order,
     * so a consolidation attempt can never refuse a sale.
     *
     * The order rides along in the context for the module that owns delivery:
     * an order the customer collects from a shop must come off that shop's
     * shelf, and that is a stronger claim than consolidation — the answerer
     * overrides rather than defers.
     *
     * @param  list<array<string,mixed>>  $items
     */
    protected function consolidateTo(array $items, ?Order $order = null): ?int
    {
        $lines = [];

        foreach ($items as $item) {
            if ($productId = (int) ($item['product_id'] ?? 0)) {
                $lines[$productId] = ($lines[$productId] ?? 0) + (int) ($item['qty'] ?? 0);
            }
        }

        return $lines === [] ? null : hook_filter('catalog.stock.prefer_warehouse', null, [
            'lines' => $lines,
            'order' => $order,
        ]);
    }

    /**
     * Is there enough on the shelf for every line of this order?
     *
     * Asked when an order that was NOT holding stock is about to start — a
     * payment arriving for an order placed hours ago, by which time somebody
     * else may have bought the last unit. Deducting anyway would put the
     * warehouse into negative numbers quietly; the usual answer to the same
     * moment is to refuse and move the order to Backordered, and so is ours.
     *
     * A product whose availability status does not GATE on its stock — a
     * preorder, or anything untracked (PLAN §31.3) — is always coverable: its
     * number was never a promise about what is on the shelf, so it is not
     * this ORDER-level `backordered` question at all. Two different things
     * are deliberately not called the same name here — see
     * `Torgash\Catalog\Stock\AvailabilityStatuses`'s own docblock.
     *
     * One query for the whole order. A product that has been deleted since is
     * not something we can promise, and counts as uncoverable.
     *
     * @param  list<array<string,mixed>>  $items
     */
    /**
     * Why a CUSTOMER may not take these lines right now — null when they may.
     *
     * Asked by {@see PlaceOrder} inside its transaction, with the products'
     * rows LOCKED, so the last unit is sold once: a second basket placing the
     * same product waits for the first to commit and then reads the shelf it
     * left. Locked in id order, so two baskets that share products cannot
     * deadlock each other. The cart already refuses to grow past the shelf;
     * this is the answer for a basket that waited while the shelf emptied, or
     * that was filled by something that never asked.
     *
     * Same rule as the cart's ({@see Product::orderLimit()}): a product that
     * cannot be ordered at all, a disabled card, one that no longer exists, or
     * more units than a status that gates on the shelf has left.
     *
     * @param  list<array<string,mixed>>  $items
     */
    public function refusal(array $items): ?string
    {
        $wanted = [];

        foreach ($items as $item) {
            if ($productId = (int) ($item['product_id'] ?? 0)) {
                $wanted[$productId] = ($wanted[$productId] ?? 0) + (int) ($item['qty'] ?? 0);
            }
        }

        if ($wanted === []) {
            return null;
        }

        ksort($wanted);

        $products = Product::whereIn('id', array_keys($wanted))->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        foreach ($wanted as $productId => $qty) {
            $product = $products->get($productId);
            $limit = $product ? $product->orderLimit() : 0;
            $name = $product ? $product->t('name') : (string) ($this->nameIn($items, $productId) ?? '#'.$productId);

            if ($limit === 0) {
                return __(':product can no longer be ordered — take it out of the basket.', ['product' => $name]);
            }

            if ($limit !== null && $qty > $limit) {
                return trans_choice(
                    'Only :count of :product is left in stock — lower the quantity in the basket.|Only :count of :product are left in stock — lower the quantity in the basket.',
                    $limit,
                    ['product' => $name],
                );
            }
        }

        return null;
    }

    /** The name a line was sold under — for a product that has since been deleted. */
    protected function nameIn(array $items, int $productId): ?string
    {
        foreach ($items as $item) {
            if ((int) ($item['product_id'] ?? 0) === $productId && ! empty($item['name'])) {
                return (string) $item['name'];
            }
        }

        return null;
    }

    public function canCover(array $items): bool
    {
        $wanted = [];

        foreach ($items as $item) {
            if ($productId = (int) ($item['product_id'] ?? 0)) {
                $wanted[$productId] = ($wanted[$productId] ?? 0) + (int) ($item['qty'] ?? 0);
            }
        }

        if ($wanted === []) {
            return true;
        }

        $products = Product::whereIn('id', array_keys($wanted))->get(['id', 'stock', 'availability_status'])->keyBy('id');
        $statuses = app(AvailabilityStatuses::class);

        foreach ($wanted as $productId => $qty) {
            $product = $products->get($productId);

            if (! $product) {
                return false;
            }

            if (! $statuses->gatesOnStock((string) $product->availability_status)) {
                continue;
            }

            if ($product->stock < $qty) {
                return false;
            }
        }

        return true;
    }

    /**
     * Put every unit of every line back — an order leaving a status that held
     * stock (cancelled, refunded).
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    public function restoreAll(array $items, ?Order $order = null): array
    {
        return app(StockMoves::class)->because($this->cause($order, 'order'), fn () => $this->restoreAllNow($items, $order));
    }

    protected function restoreAllNow(array $items, ?Order $order = null): array
    {
        foreach ($items as $i => $item) {
            $product = Product::find($item['product_id']);
            if (! $product) {
                continue;
            }

            $product->adjustStock($item['qty'], ['restore' => $item['warehouses'] ?? null]);
            unset($items[$i]['warehouses']);
        }

        return array_values($items);
    }

    /**
     * Take $qty units off a stored breakdown, returning the slice to credit and
     * leaving the remainder behind. Values are negative — they are deltas a
     * deduction applied — so "available" is the amount with the sign flipped.
     *
     * @param  array<int,int>  $breakdown  modified in place
     * @return array<int,int>
     */
    protected function slice(array &$breakdown, int $qty): array
    {
        $slice = [];

        foreach ($breakdown as $warehouseId => $delta) {
            if ($qty <= 0) {
                break;
            }

            $available = -$delta;
            if ($available <= 0) {
                continue;
            }

            $take = min($qty, $available);
            $slice[$warehouseId] = -$take;
            $breakdown[$warehouseId] = $delta + $take;
            $qty -= $take;

            if ($breakdown[$warehouseId] === 0) {
                unset($breakdown[$warehouseId]);
            }
        }

        return $slice;
    }

    /**
     * @param  list<array<string,mixed>>  $items
     * @return array<int, array<string,mixed>>
     */
    protected function byLine(array $items): array
    {
        $keyed = [];
        foreach ($items as $item) {
            $keyed[static::lineKey($item)] = $item;
        }

        return $keyed;
    }

    /**
     * The same lines summed PER PRODUCT — what a return deals in: two units of
     * one processor coming back are two units, whether they were bought inside
     * a server or on their own, and the shelf does not care which line they
     * sat on. Their warehouse breakdowns are merged for the same reason.
     *
     * @param  list<array<string,mixed>>  $items
     * @return array<int, array{product_id: int, qty: int, warehouses: array<int,int>}>
     */
    protected function byProductId(array $items): array
    {
        $summed = [];

        foreach ($items as $item) {
            $productId = (int) ($item['product_id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            $summed[$productId] ??= ['product_id' => $productId, 'qty' => 0, 'warehouses' => []];
            $summed[$productId]['qty'] += (int) ($item['qty'] ?? 0);

            foreach ((array) ($item['warehouses'] ?? []) as $warehouseId => $delta) {
                $summed[$productId]['warehouses'][$warehouseId] =
                    ($summed[$productId]['warehouses'][$warehouseId] ?? 0) + (int) $delta;
            }
        }

        return $summed;
    }

    /**
     * How one LINE of an order is told from another (PLAN §33.3).
     *
     * By its key, which since configured products exist is what a line is
     * addressed by — one order can hold the same processor twice, once inside
     * a server and once on its own, and keying by product id alone silently
     * collapsed the two into whichever came last. A line written before keys
     * existed has none, and falls back to the product id, which is exactly
     * what it used to be keyed by.
     *
     * @param  array<string, mixed>  $item
     */
    protected static function lineKey(array $item): string
    {
        $key = (string) ($item['key'] ?? '');

        return $key !== '' ? $key : (string) (int) ($item['product_id'] ?? 0);
    }

    /**
     * Why the stock moved, for the journal (PLAN §42): this order, and
     * whether it was the order itself or goods coming back. A cause already
     * in force (a merge, a return with its own number) is kept.
     *
     * @return array<string, mixed>
     */
    protected function cause(?Order $order, string $type): array
    {
        return array_filter(['type' => $type, 'order_id' => $order?->id, 'order_number' => $order?->order_number]);
    }
}
