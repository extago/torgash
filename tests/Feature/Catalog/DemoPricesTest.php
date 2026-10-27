<?php

namespace Tests\Feature\Catalog;

use App\Core\Demo\DemoPack;
use App\Models\Currency;
use App\Models\Storefront;
use Tests\TestCase;
use Torgash\Catalog\Demo\CatalogDemo;
use Torgash\Catalog\Models\Product;

/**
 * The demo's prices are dollars in the pack, and the shop's own money once
 * installed. A Russian shop used to get every demo product "typed in USD",
 * because the seeder makes dollars in every shop and that was the test (found
 * on the first live shop, 2026-10-21).
 */
class DemoPricesTest extends TestCase
{
    public function test_a_rouble_shops_demo_is_priced_in_roubles(): void
    {
        Currency::updateOrCreate(['code' => 'RUB'], ['symbol' => '₽', 'name' => 'Rouble', 'rate' => 1, 'decimals' => 0, 'enabled' => true]);
        $storefront = Storefront::where('is_default', true)->firstOrFail();
        $storefront->update(['default_currency' => 'RUB', 'currencies' => ['RUB']]);
        // The shop's books are the CURRENT storefront's currency.
        $this->app->instance('torgash.storefront', $storefront);

        $pack = new DemoPack(base_path('database/demo'));
        $first = $pack->json('catalog/products')[0];
        $rate = (float) $pack->json('catalog/settings')['rates']['RUB'];

        app(CatalogDemo::class)->install($pack);

        $product = Product::where('sku', $first['sku'])->firstOrFail();

        $this->assertNull($product->price_currency, 'typed in the books currency, not in dollars');
        $this->assertSame((int) round($first['price'] * $rate) * 100, (int) $product->price);
    }
}
