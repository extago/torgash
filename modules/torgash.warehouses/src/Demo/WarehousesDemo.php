<?php

namespace Torgash\Warehouses\Demo;

use App\Core\Demo\DemoPack;
use Torgash\Catalog\Models\Product;
use Torgash\Warehouses\Models\Warehouse;
use Torgash\Warehouses\StockAllocator;

/**
 * Its part of the demo shop (PLAN §55.3): two shops with pickup and opening
 * hours, and every demo product's stock spread over them and the default
 * warehouse — so a product page says where it is in stock, and the mall,
 * which lacks every third product, shows what "not in this shop" looks like.
 *
 * Read from `database/demo/warehouses/warehouses.json`. A shop's `share` is
 * the part of each product's stock it holds; the default warehouse keeps the
 * rest. Quantities are set through StockAllocator::set(), the stock screen's
 * own way, so the product's total and the stock journal follow. The shops
 * stand in the town the shop dispatches from, else the shop's own town when
 * the towns module knows it, else its country's capital; with none of those they are made without a town and
 * the owner gives them one — a pickup point is listed by its town.
 */
class WarehousesDemo
{
    public function install(DemoPack $pack): void
    {
        $default = Warehouse::query()->where('is_default', true)->first();
        $place = Warehouse::dispatching()?->place_id ?? $this->shopTown();
        $shops = [];

        foreach ($pack->json('warehouses/warehouses') as $sort => $row) {
            $shops[] = [
                'warehouse' => Warehouse::create([
                    'name' => $row['name'],
                    'address' => $row['address'] ?? null,
                    'hours' => $row['hours'] ?? null,
                    'place_id' => $place,
                    'is_public' => true,
                    'is_pickup' => true,
                    'enabled' => true,
                    'sort' => 10 + $sort,
                ]),
                'share' => (float) ($row['share'] ?? 0.2),
                'every' => (int) ($row['every'] ?? 0),
            ];
        }

        if (! $default || $shops === []) {
            return;
        }

        $stock = app(StockAllocator::class);
        $products = Product::query()->whereIn('id', app(\App\Core\Demo\DemoData::class)->ids(Product::class))->orderBy('id')->get(['id', 'stock']);

        foreach ($products as $i => $product) {
            $left = max(0, (int) $product->stock);

            foreach ($shops as $shop) {
                $here = $shop['every'] > 0 && $i % $shop['every'] === 0 ? 0 : (int) floor($product->stock * $shop['share']);
                $stock->set($product->id, $shop['warehouse']->id, $here);
                $left -= $here;
            }

            $stock->set($product->id, $default->id, max(0, $left));
        }

        $pack->say(count($shops).' shops with pickup, stock of '.$products->count().' products spread.');
    }

    /**
     * The shop's own town, when the towns module is there to name it — else
     * the capital of the shop's country, which a new shop has as soon as its
     * country's pack is in (the installer loads it).
     */
    protected function shopTown(): ?int
    {
        $guess = 'Torgash\\Locations\\TownGuess';
        $places = 'Torgash\\Locations\\Models\\Place';

        if (! class_exists($guess)) {
            return null;
        }

        $country = strtoupper(trim((string) setting(\App\Core\Settings\CoreSettings::ID, 'default_country')));

        return app($guess)->shopTown()?->id
            ?? ($country === '' ? null : $places::query()->where('country', $country)->where('type', 'capital')->value('id'));
    }
}
