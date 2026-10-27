<?php

namespace Torgash\Catalog\Stock;

/**
 * "The stock of this product moved" — announced from the few places a number
 * really changes, with WHY it changed (PLAN §42).
 *
 * The number changes in exactly two kinds of place: a product whose stock is
 * not kept per warehouse has its own column (moved by `Product::adjustStock()`
 * and by anything that saves the product — the form, an import, 1C), and a
 * product kept per warehouse has one row per warehouse (moved by the
 * warehouses module). Those places call `record()`, and only they: a caller
 * that merely CAUSES a movement — an order, a return, a document, an import —
 * says so with `because()` around its work, and every movement inside is
 * written down with that cause. The outermost cause wins, so a return that
 * goes through the order's own stock code is a return, not an order edit.
 *
 * What happens to the announcement (`catalog.stock.moved`) is somebody else's
 * business — `torgash.inventory` keeps a journal of it; a shop without that
 * module pays one hook call per movement and keeps nothing. A per-request
 * singleton, forgotten when the request is rebound.
 */
class StockMoves
{
    /** @var list<array<string, mixed>> */
    protected array $causes = [];

    protected int $quiet = 0;

    /**
     * Run `$work` with every movement inside it put down to `$cause` —
     * `['type' => 'order', 'order_id' => 12]`, `['type' => 'import']`. A cause
     * already in force is kept: the outermost says what the act was.
     *
     * @template T
     *
     * @param  array<string, mixed>  $cause
     * @param  callable(): T  $work
     * @return T
     */
    public function because(array $cause, callable $work): mixed
    {
        if ($this->causes !== []) {
            return $work();
        }

        $this->causes[] = $cause;

        try {
            return $work();
        } finally {
            array_pop($this->causes);
        }
    }

    /** @return array<string, mixed> the cause in force, or a person's edit by default */
    public function cause(): array
    {
        return $this->causes[0] ?? ['type' => 'edit'];
    }

    /**
     * Run `$work` without the product model's own save announcing a movement —
     * for the one writer that announces it itself with more to say.
     *
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function quietly(callable $work): mixed
    {
        $this->quiet++;

        try {
            return $work();
        } finally {
            $this->quiet--;
        }
    }

    public function isQuiet(): bool
    {
        return $this->quiet > 0;
    }

    /**
     * A movement: `$delta` units of `$productId`, at a warehouse or on the
     * product's own column (null), leaving `$after` there.
     */
    public function record(int $productId, ?int $warehouseId, int $delta, ?int $after = null, ?array $cause = null): void
    {
        if ($delta === 0) {
            return;
        }

        $user = auth()->user();

        hook_action('catalog.stock.moved', [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'delta' => $delta,
            'after' => $after,
            // A cause named by the writer itself (a split is always a split)
            // over whatever act is in force.
            'cause' => $cause ?? $this->cause(),
            'user_id' => $user?->id,
            'user_name' => $user?->name,
        ]);
    }
}
