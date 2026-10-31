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

    /** Only the photo and the name lead to the product: the code and the
     *  price are text a shopper selects and copies, which a link swallows. */
    public function test_only_the_photo_and_the_name_are_links(): void
    {
        $category = Category::create(['name' => ['en' => 'Card links'], 'slug' => 'card-links']);
        $product = Product::create(['sku' => 'CARD-LINK-1', 'name' => ['en' => 'Linked thing'], 'slug' => 'linked-thing', 'price' => 100_00, 'stock' => 3, 'status' => 'active']);
        $product->categories()->attach($category->id);

        $html = $this->get('http://localhost/catalog/card-links')->assertOk()->getContent();

        $dom = new \DOMDocument;
        @$dom->loadHTML('<?xml encoding="utf-8">'.$html);
        $xpath = new \DOMXPath($dom);
        $card = $xpath->query('//div[contains(concat(" ", @class, " "), " torgash-card ")]')->item(0);
        $this->assertNotNull($card);

        // The corner's quick view is a link to the product too (its address
        // for a browser without HTMX) — a button of the corner, not the card's.
        $links = $xpath->query('.//a[contains(@href, "/product/linked-thing")][not(ancestor::*[contains(@class, "torgash-card-corner")])]', $card);
        $this->assertSame(2, $links->length);
        $this->assertSame(1, $xpath->query('.//figure', $links->item(0))->length);
        $this->assertSame('Linked thing', trim($links->item(1)->textContent));

        $this->assertSame(0, $xpath->query('.//a[contains(., "CARD-LINK-1")] | .//a[.//*[contains(@class, "torgash-price")]]', $card)->length);
        $this->assertStringContainsString('SKU: CARD-LINK-1', $card->textContent);
    }
}
