<?php

namespace Torgash\CommerceMl\Import;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Torgash\Catalog\Models\Product;
use Torgash\Catalog\Models\ProductPriceTypeOverride;
use Torgash\Catalog\Pricing\Prices;
use Torgash\CommerceMl\Models\Link;
use Torgash\CommerceMl\Xml\Node;

/**
 * `offers.xml` — what a thing costs and how many there are.
 *
 * Prices are the part where an exchange can do real damage, and two rules keep
 * it from doing so.
 *
 * A price type 1C has not been matched to anything writes NOTHING. Not the base
 * price, not a guess: a shop that sells at retail and buys at wholesale sends
 * both down the same wire, and an importer that takes "the first price it sees"
 * is an importer that will one day publish the purchase price. The unmatched
 * type sits in the queue and the screen counts it.
 *
 * And the money itself goes through `Prices`, the one class that owns the
 * arithmetic. 1C sends major units and names a currency; what is stored is the
 * typed number in that currency plus a books amount DERIVED from it, exactly as
 * the admin form stores a price typed in euro. Writing `price` directly would
 * put hryvnia in a dollar column and nothing would say so.
 */
class OffersImport
{
    /** The local sides that are a column rather than a row. */
    public const BASE = 'base';

    public const LIST = 'list';

    public const COST = 'cost';

    public function __construct(protected Matching $matching, protected Prices $prices) {}

    /**
     * The `ТипыЦен` vocabulary at the head of the file — recorded before any
     * offer is read, so the screen has something to ask about even when the
     * first exchange writes nothing at all.
     */
    public function priceTypes(Node $package): int
    {
        $seen = 0;

        foreach ($package->each('price_types', 'price_type') as $type) {
            $guid = $type->text('id');

            if ($guid !== null) {
                $this->matching->see(Link::KIND_PRICE_TYPE, $guid, $type->text('name'));
                $seen++;
            }
        }

        return $seen;
    }

    /** @return string one of updated|skipped */
    public function run(Node $offer): string
    {
        $guid = $offer->text('id');

        if ($guid === null || ! setting('torgash.commerceml', 'import_offers', true)) {
            return 'skipped';
        }

        $product = Product::withTrashed()->where('external_id', $guid)->first();

        if (! $product) {
            // An offer for a product that was not imported — because the shop
            // said "update only", or because it is still waiting in a queue.
            // Not an error, and not something to invent a product for.
            return 'skipped';
        }

        $touched = $this->prices($product, $offer);
        $touched = $this->stock($product, $offer) || $touched;

        return $touched ? 'updated' : 'skipped';
    }

    // ---------------------------------------------------------------- prices

    protected function prices(Product $product, Node $offer): bool
    {
        $typed = [];
        $currency = null;
        $overrides = [];

        foreach ($offer->each('prices', 'price') as $price) {
            $typeGuid = $price->text('price_type_id');
            $amount = $price->float('price_per_unit');

            if ($typeGuid === null || $amount === null) {
                continue;
            }

            if ($this->matching->decision(Link::KIND_PRICE_TYPE, $typeGuid) !== Link::LINK) {
                continue;
            }

            $currency ??= $price->text('currency');
            $key = $this->matching->localKey(Link::KIND_PRICE_TYPE, $typeGuid);
            $localId = $this->matching->localId(Link::KIND_PRICE_TYPE, $typeGuid);

            match (true) {
                $key === self::BASE => $typed['price'] = $amount,
                $key === self::LIST => $typed['list_price'] = $amount,
                $key === self::COST => $typed['cost_price'] = $amount,
                // Anything else is one of the shop's own price types, and lands
                // as a per-product override rather than on the row.
                $localId !== null => $overrides[$localId] = $amount,
                default => null,
            };
        }

        if ($typed !== []) {
            // Major units in, and the currency 1C quoted them in. Prices keeps
            // the typed number and derives the books amount, which is what makes
            // a later rate change reprice this product correctly.
            $product->fill($this->prices->columns($typed, $currency))->save();
        }

        // A price type's own price for this product, through Prices like the
        // product's own amounts: it used to be written as if 1C always quoted
        // in the shop's books, so a euro price landed as that many hryvnia and
        // a later rate change never moved it.
        foreach ($overrides as $priceTypeId => $amount) {
            ProductPriceTypeOverride::updateOrCreate(
                ['product_id' => $product->id, 'price_type_id' => $priceTypeId],
                $this->prices->row($amount, $currency),
            );
        }

        return $typed !== [] || $overrides !== [];
    }

    // ----------------------------------------------------------------- stock

    /**
     * `Количество`, or the per-warehouse breakdown when 1C sends one.
     *
     * The breakdown is the better answer and is used whenever it is there: a
     * shop running the warehouses module needs to know WHERE the stock is, and
     * a total is what you compute from that rather than the other way round.
     */
    protected function stock(Product $product, Node $offer): bool
    {
        $byWarehouse = [];

        foreach ($offer->all('warehouse') as $warehouse) {
            $guid = $warehouse->attr('warehouse_id') ?? $warehouse->text('warehouse_id');
            $qty = $warehouse->attr('warehouse_quantity') ?? $warehouse->text('warehouse_quantity');

            if ($guid !== null && $qty !== null) {
                $byWarehouse[$guid] = (int) $qty;
            }
        }

        if ($byWarehouse !== [] && Schema::hasTable('m_warehouse_product_stock')) {
            return $this->splitStock($product, $byWarehouse);
        }

        $total = $offer->int('quantity') ?? ($byWarehouse === [] ? null : array_sum($byWarehouse));

        if ($total === null) {
            return false;
        }

        // Never negative: 1C's minus one is a reservation of its own, and a
        // storefront that reads it as "minus one in stock" sells nothing and
        // says nothing.
        $product->forceFill(['stock' => max(0, $total)])->save();

        return true;
    }

    /** @param array<string, int> $byWarehouse */
    protected function splitStock(Product $product, array $byWarehouse): bool
    {
        $warehouses = DB::table('m_warehouses')
            ->whereIn('external_id', array_keys($byWarehouse))
            ->pluck('id', 'external_id');

        if ($warehouses->isEmpty()) {
            // 1C names warehouses the shop has never linked. Falling back to the
            // total is better than writing nothing, and the exchange log says
            // which identifiers went unrecognised.
            $product->forceFill(['stock' => max(0, array_sum($byWarehouse))])->save();

            return true;
        }

        DB::transaction(function () use ($product, $byWarehouse, $warehouses) {
            foreach ($byWarehouse as $guid => $qty) {
                $warehouseId = $warehouses->get($guid);

                if ($warehouseId === null) {
                    continue;
                }

                $was = (int) DB::table('m_warehouse_product_stock')
                    ->where('warehouse_id', $warehouseId)->where('product_id', $product->id)->value('qty');

                DB::table('m_warehouse_product_stock')->updateOrInsert(
                    ['warehouse_id' => $warehouseId, 'product_id' => $product->id],
                    ['qty' => max(0, $qty)],
                );

                // Said, with the cause in force (1C), for whoever keeps a journal.
                app(\Torgash\Catalog\Stock\StockMoves::class)->record($product->id, (int) $warehouseId, max(0, $qty) - $was, max(0, $qty));
            }

            // The aggregate is kept equal to the sum of the rows, the same way
            // every other writer of this table keeps it — through the catalogue,
            // so a delivery arriving from 1C is heard ("back in stock") like
            // any other.
            $sum = (int) DB::table('m_warehouse_product_stock')->where('product_id', $product->id)->sum('qty');
            \Torgash\Catalog\Models\Product::syncStockTo($product->id, $sum);
        });

        return true;
    }
}
