<?php

namespace Tests\Feature\Catalog;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;

/** The SKU line on catalogue cards is the shop's choice; the product page keeps it. */
class CardSkuTest extends TestCase
{
    public function test_the_sku_on_cards_can_be_switched_off(): void
    {
        $category = Category::create(['name' => ['en' => 'Card SKU'], 'slug' => 'card-sku']);
        $product = Product::create(['sku' => 'CARD-SKU-1', 'name' => ['en' => 'Card thing'], 'slug' => 'card-thing', 'price' => 100_00, 'stock' => 3, 'status' => 'active']);
        $product->categories()->attach($category->id);

        $this->get('http://localhost/catalog/card-sku')->assertOk()->assertSee('CARD-SKU-1');

        app(SettingsManager::class)->set('torgash.catalog', 'card_sku', false);

        $this->get('http://localhost/catalog/card-sku')->assertOk()->assertSee('Card thing')->assertDontSee('CARD-SKU-1');
        $this->get('http://localhost/catalog/card-sku?view=list')->assertOk()->assertDontSee('CARD-SKU-1');
        $this->get('http://localhost/product/card-thing')->assertOk()->assertSee('CARD-SKU-1');
    }
}
