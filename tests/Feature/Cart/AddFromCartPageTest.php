<?php

namespace Tests\Feature\Cart;

use Tests\TestCase;
use Torgash\Catalog\Models\Product;

/**
 * Adding from the basket page itself — "You may also like" under the basket.
 *
 * Every buy form used to AIM at the header badge, and HTMX does not send a
 * request whose target is missing. The cart and checkout draw a quiet header
 * with no badge (on purpose), so on the very page that shows the basket an
 * add did nothing at all — empty basket or not. Now the forms swap nothing
 * themselves and the answer brings, out of band, what the page says it has
 * (`X-Torgash-Cart-Shows`): on the basket page, the basket.
 */
class AddFromCartPageTest extends TestCase
{
    public function test_no_buy_form_depends_on_the_header_badge_being_there(): void
    {
        $this->product('AFC-1', 'Afc kettle');

        $html = $this->get('http://localhost/catalog')->assertOk()->getContent();

        $this->assertStringNotContainsString('hx-target="#cart-badge"', $html);
        $this->assertMatchesRegularExpression('/<form hx-post="[^"]*\/cart\/add\/afc-1" hx-swap="none"/', $html);
    }

    public function test_an_add_from_the_basket_page_redraws_the_basket_and_not_a_badge_it_has_not_got(): void
    {
        $this->product('AFC-2', 'Afc toaster');

        $html = $this->post('http://localhost/cart/add/afc-2', ['qty' => 1], [
            'HX-Request' => 'true',
            'X-Torgash-Cart-Shows' => 'tabbar,contents',
        ])->assertOk()->getContent();

        $this->assertStringContainsString('id="cart-contents" hx-swap-oob="innerHTML"', $html);
        $this->assertStringContainsString('Afc toaster', $html);
        $this->assertStringContainsString('id="tabbar-badge-cart" hx-swap-oob="true"', $html);
        // A fragment with nowhere to go is an HTMX error on the page.
        $this->assertStringNotContainsString('id="cart-badge"', $html);
    }

    public function test_an_add_elsewhere_brings_the_badge_and_leaves_the_basket_alone(): void
    {
        $this->product('AFC-3', 'Afc mixer');

        $html = $this->post('http://localhost/cart/add/afc-3', ['qty' => 1], [
            'HX-Request' => 'true',
            'X-Torgash-Cart-Shows' => 'badge,tabbar',
        ])->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/id="cart-badge"[^>]*hx-swap-oob="true"/s', $html);
        $this->assertStringNotContainsString('id="cart-contents"', $html);
    }

    protected function product(string $sku, string $name): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => ['en' => $name],
            'slug' => strtolower($sku),
            'price' => 100_00,
            'stock' => 10,
            'status' => 'active',
            'created_at' => now()->addYear(),
        ]);
    }
}
