<?php

namespace Torgash\Catalog\Stock;

use Illuminate\Database\Eloquent\Builder;
use Torgash\Catalog\Models\AvailabilityStatus;
use Torgash\Catalog\Models\Product;

/**
 * Whether a product can be bought right now, and whether its stock number
 * even applies — a SECOND axis from `status` (PLAN §31.3).
 *
 * `status` (active/hidden/disabled) is the admin's own lifecycle for the
 * card and stays untouched. This is closer to an order's `payment_state`:
 * a fixed vocabulary a shop and its modules can extend, never a column a
 * caller compares by hand. Open from the start, registered lazily through
 * `catalog.availability_statuses` — the same shape as `Filters\Flags` and
 * for the same reason: a warehouses module or a shop-specific one can add
 * "in transit" or "discontinued" without touching this class.
 *
 * Each definition states three STRUCTURAL facts about what the status means,
 * never a shop preference (those are settings elsewhere):
 *
 * `orderable` — can it be put in a cart at all, independent of the number.
 *
 * `tracks_stock` — does the quantity apply to this status. False means the
 * number is purely informational: {@see Product::adjustStock()} never moves
 * it for such a product and {@see \Torgash\Checkout\Orders\OrderStock::canCover()}
 * never compares against it — a digital or service product's "stock" column
 * can sit at whatever an import left it without meaning anything.
 *
 * `allow_negative` — only asked when `tracks_stock` is true: may the number
 * cross zero without blocking the button. A preorder's whole point.
 *
 * **A status nobody answers for any more fails CLOSED** — treated as not
 * orderable and as gating on stock — the same rule an unknown filter value or
 * flag key already follows (§102): letting a sale through for a status the
 * shop can no longer even name is the one answer that misleads.
 *
 * **The shop has a word too** (PLAN §31.6), in `m_catalog_availability_statuses`
 * and on the Availability statuses screen: it may RENAME any status, give any
 * status a WEIGHT in the listing, and invent statuses of its own. What it may
 * not do is change what a status registered in code MEANS — that code relies on
 * `orderable` & co. being what it declared — so a table row for such a key
 * supplies only the name and the weight, and code wins on a collision.
 */
class AvailabilityStatuses
{
    /** The weight of a status nobody weighed: what can be bought above what cannot — the two groups the tumbler always drew. */
    public const ORDERABLE_WEIGHT = 10;

    public const UNORDERABLE_WEIGHT = 0;

    /** Never a registered status: {@see Product::effectiveAvailabilityKey()}'s presentational nuance. */
    public const RESERVED_KEYS = ['low_stock'];

    /** @var array<string, array{label: array<string, string>, orderable: bool, tracks_stock: bool, allow_negative: bool, schema: string|null, sort_weight: int, weighed: bool, source: string, renamed: bool}>|null */
    protected ?array $statuses = null;

    /**
     * Every status, in registration order — code's first, then the shop's own
     * in the order they were created.
     *
     * `source` is `code` or `shop`; `weighed` says whether the weight was
     * stated or derived; `renamed` whether the label carries the shop's words.
     *
     * @return array<string, array{label: array<string, string>, orderable: bool, tracks_stock: bool, allow_negative: bool, schema: string|null, sort_weight: int, weighed: bool, source: string, renamed: bool}>
     */
    public function all(): array
    {
        if ($this->statuses !== null) {
            return $this->statuses;
        }

        $statuses = [];

        foreach (hook_filter('catalog.availability_statuses', [], []) as $key => $definition) {
            if (! is_string($key) || $key === '' || in_array($key, self::RESERVED_KEYS, true)) {
                continue;
            }

            $statuses[$key] = [
                'label' => $definition['label'] ?? [],
                'orderable' => (bool) ($definition['orderable'] ?? false),
                'tracks_stock' => (bool) ($definition['tracks_stock'] ?? true),
                'allow_negative' => (bool) ($definition['allow_negative'] ?? false),
                'schema' => is_string($definition['schema'] ?? null) ? $definition['schema'] : null,
                'sort_weight' => 0,
                'weighed' => false,
                'source' => 'code',
                'renamed' => false,
            ];
        }

        foreach (AvailabilityStatus::query()->orderBy('id')->get() as $row) {
            $typed = array_filter((array) $row->label, fn ($v) => is_string($v) && trim($v) !== '');

            if (isset($statuses[$row->key])) {
                // Code's status: its meaning stays, the shop's words win
                // locale by locale — a rename in Russian keeps the English.
                // Code declares its word in English (translated by lang files);
                // as a map it keeps what the shop did not retype translatable.
                $code = $statuses[$row->key]['label'];
                $statuses[$row->key]['label'] = $typed + (is_array($code) ? $code : ['en' => (string) $code]);
                $statuses[$row->key]['renamed'] = $typed !== [];
            } else {
                $statuses[$row->key] = [
                    'label' => $typed,
                    'orderable' => $row->orderable,
                    'tracks_stock' => $row->tracks_stock,
                    'allow_negative' => $row->allow_negative,
                    'schema' => $row->schema ?: null,
                    'sort_weight' => 0,
                    'weighed' => false,
                    'source' => 'shop',
                    'renamed' => false,
                ];
            }

            if ($row->sort_weight !== null) {
                $statuses[$row->key]['sort_weight'] = $row->sort_weight;
                $statuses[$row->key]['weighed'] = true;
            }
        }

        foreach ($statuses as $key => $def) {
            if (! $def['weighed']) {
                $statuses[$key]['sort_weight'] = $def['orderable'] ? self::ORDERABLE_WEIGHT : self::UNORDERABLE_WEIGHT;
            }
        }

        return $this->statuses = $statuses;
    }

    /** @return array<string, mixed>|null */
    public function find(string $key): ?array
    {
        return $this->all()[$key] ?? null;
    }

    public function label(string $key): string
    {
        $label = $this->find($key)['label'] ?? [];

        // A shop's own status may have been named in one language only.
        return label_text($label, null, $key);
    }

    /** key => label, for the product form's select and the settings screen. */
    public function options(): array
    {
        $options = [];

        foreach (array_keys($this->all()) as $key) {
            $options[$key] = $this->label($key);
        }

        return $options;
    }

    /**
     * Can this product be put in a cart RIGHT NOW.
     *
     * `orderable` gates first — a status that says no means no whatever the
     * number is (a discontinued product with 50 left is still not for sale).
     * Only a status that both tracks the number AND refuses negative values
     * then asks the number's own question.
     */
    public function isOrderable(Product $product): bool
    {
        return $this->isOrderableFor((string) $product->availability_status, (int) $product->stock);
    }

    /**
     * The same question about a status and a number that are not (yet) a loaded
     * product — the state a row was in BEFORE a write, which is what "has this
     * product just come back" is measured against
     * ({@see \Torgash\Catalog\Models\Product::syncStockTo()}).
     *
     * Separate from isOrderable() only so that the caller need not hydrate a
     * model to ask: the warehouses module writes the aggregate for every
     * product it touches, and that runs per line of an order.
     */
    public function isOrderableFor(string $key, int $stock): bool
    {
        $def = $this->find($key);

        if (! $def || ! $def['orderable']) {
            return false;
        }

        return ! ($def['tracks_stock'] && ! $def['allow_negative'] && $stock <= 0);
    }

    /**
     * How many of this product a customer may order right now: null when the
     * number does not limit it (a preorder, an untracked status), otherwise
     * what is on the shelf — and 0 when it cannot be ordered at all.
     *
     * The quantity half of {@see isOrderable()}, asked by everything that puts
     * a number into a basket or an order — the cart, the API, the checkout —
     * so a stepper capped at the shelf in the browser is also capped where the
     * browser cannot be trusted.
     */
    public function orderLimit(Product $product): ?int
    {
        if (! $this->isOrderable($product)) {
            return 0;
        }

        return $this->gatesOnStock((string) $product->availability_status)
            ? max(0, (int) $product->stock)
            : null;
    }

    /**
     * Whether the stock NUMBER means anything at all for this status — what
     * {@see \Torgash\Catalog\Models\Product::adjustStock()} asks before
     * touching the column, so an order for an untracked product never moves a
     * count that was never counting anything.
     */
    public function tracksStock(string $key): bool
    {
        return (bool) ($this->find($key)['tracks_stock'] ?? true);
    }

    /**
     * Whether the stock number GATES ordering for this status — true only for
     * a tracked status that refuses to go negative. What
     * {@see \Torgash\Checkout\Orders\OrderStock::canCover()} asks instead of
     * comparing every product's quantity the same way: a preorder or an
     * untracked product is always coverable, because its number was never a
     * promise about what is on the shelf.
     *
     * Unknown status: true — the conservative, pre-existing answer (compare
     * against the shelf) rather than "let it through because nobody can say".
     */
    public function gatesOnStock(string $key): bool
    {
        $def = $this->find($key);

        return ! $def || ($def['tracks_stock'] && ! $def['allow_negative']);
    }

    /**
     * Narrows a product query to what is orderable right now — the SQL
     * equivalent of {@see isOrderable()}, for the "in stock only" filter box
     * and the "out of stock last" sort tumbler, neither of which may filter a
     * PAGE after the fact (Flags' own rule: a paginated grid needs this in the
     * WHERE clause, not in a PHP predicate run on the loaded page).
     */
    public function applyOrderable(Builder $query): Builder
    {
        $always = [];
        $stockGated = [];

        foreach ($this->all() as $key => $def) {
            if (! $def['orderable']) {
                continue;
            }

            if ($def['tracks_stock'] && ! $def['allow_negative']) {
                $stockGated[] = $key;
            } else {
                $always[] = $key;
            }
        }

        if ($always === [] && $stockGated === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($always, $stockGated) {
            if ($always !== []) {
                $q->orWhereIn('availability_status', $always);
            }

            if ($stockGated !== []) {
                $q->orWhere(fn (Builder $sub) => $sub
                    ->whereIn('availability_status', $stockGated)
                    ->where('stock', '>', 0));
            }
        });
    }

    /**
     * Orders a listing by the WEIGHT of each product's status — higher first,
     * equal weights mixed, and the sort the visitor chose decides the order
     * within each weight ({@see \Torgash\Catalog\Sorting\Sorts::applyKey()}).
     *
     * A weight nobody stated is derived (10 for what can be bought, 0 for what
     * cannot), so a shop that never opens the statuses screen gets exactly the
     * two groups "out of stock last" always drew.
     *
     * A product ranks as what it can be BOUGHT as, not as its bare column: an
     * orderable status that gates on stock (plain "in stock", or a shop's own
     * one that does) with nothing left ranks as Out of stock — the same line
     * {@see applyOrderable()} draws for the "in stock only" box, so the box and
     * the ranking cannot disagree about which products are sold out. With no
     * `out_of_stock` registered it takes the lowest weight there is, and so
     * does a status nobody answers for any more (fail closed, as everywhere
     * here).
     *
     * Weights are integers typed by the shop and cast here, so they are written
     * into the SQL rather than bound: a bound `then ?` reaches Postgres with no
     * type, is resolved as TEXT, and then "10" sorts below "2".
     *
     * Every row sharing one weight orders nothing, so the whole clause is left
     * out — a sort key every row shares is a no-op anyway.
     */
    public function applyRank(Builder $query): Builder
    {
        $all = $this->all();
        $weights = array_map(fn (array $def) => (int) $def['sort_weight'], $all);
        $floor = min([0, ...array_values($weights)]);
        $soldOut = $weights['out_of_stock'] ?? $floor;

        // Only where the NUMBER decides: a status that cannot be bought anyway
        // ("needs confirming", weighed 10 by the shop) keeps its own weight at
        // zero stock, or the shop's ordering would depend on a count it never
        // looks at.
        $stockGated = array_keys(array_filter($all, fn (array $def) => $def['orderable'] && $def['tracks_stock'] && ! $def['allow_negative']));

        if (count(array_unique([...array_values($weights), $soldOut, $floor])) <= 1) {
            return $query;
        }

        $status = $query->qualifyColumn('availability_status');
        $stock = $query->qualifyColumn('stock');
        $sql = 'case';
        $bindings = [];

        if ($stockGated !== []) {
            $sql .= " when {$status} in (".implode(',', array_fill(0, count($stockGated), '?')).") and {$stock} <= 0 then {$soldOut}";
            $bindings = [...$bindings, ...$stockGated];
        }

        $byWeight = [];

        foreach ($weights as $key => $weight) {
            $byWeight[$weight][] = $key;
        }

        foreach ($byWeight as $weight => $keys) {
            $sql .= " when {$status} in (".implode(',', array_fill(0, count($keys), '?')).") then {$weight}";
            $bindings = [...$bindings, ...$keys];
        }

        return $query->orderByRaw($sql." else {$floor} end desc", $bindings);
    }

    /**
     * schema.org's own vocabulary — https://schema.org/ItemAvailability —
     * so the head's JSON-LD says PreOrder/Discontinued the moment a shop
     * registers those statuses, instead of a bare InStock/OutOfStock that
     * cannot tell a crawler which one it is.
     *
     * Unknown or undeclared: OutOfStock, the same fail-closed default as
     * everywhere else here — never advertise buyability nobody vouches for.
     */
    public function schemaAvailability(string $key): string
    {
        $uri = $this->find($key)['schema'] ?? null;

        return $uri ?? 'https://schema.org/OutOfStock';
    }

    /**
     * The status a tracked, non-negative status moves to ON ITS OWN once its
     * stock crosses zero — a SHOP POLICY (`availability_on_zero_status`
     * setting), never a fact baked into the status definition, because
     * whether "in stock" running out should open a preorder or simply close
     * the sale is a decision only the shop can make. The one automatic
     * transition in this whole system: everything else (a shipment arriving,
     * production resuming) is a fact only a person or 1C can supply, so it is
     * always an ordinary edit — see {@see \Torgash\Catalog\Models\Product::adjustStock()}.
     */
    public function onZeroTarget(): ?string
    {
        $target = (string) setting('torgash.catalog', 'availability_on_zero_status', 'out_of_stock');

        return $target !== '' && $this->find($target) ? $target : null;
    }

    /** For tests and for a settings screen that has just saved. */
    public function flush(): void
    {
        $this->statuses = null;
    }
}
