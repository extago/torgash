<?php

namespace Tests\Feature\Cart;

use Tests\Concerns\MakesVariationAxes;
use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Which variation a line is: printed in the basket and at checkout, kept in
 * the order (OrderLines) for the invoice and the letters, and switched right
 * on the cart page (CartController::swapVariation).
 */
class LineVariationTest extends TestCase
{
    use MakesVariationAxes;

    /** @return array{0: Product, 1: Product, 2: Product} black, white, white sold out */
    protected function group(): array
    {
        [, $colours] = $this->variationAxis('LV Colour', ['Black', 'White', 'Red']);
        $make = fn (string $sku, int $stock) => Product::create([
            'sku' => $sku, 'name' => ['en' => 'Phone '.$sku], 'slug' => strtolower($sku), 'price' => 100_00,
            'stock' => $stock, 'status' => 'active', 'variation_group' => 'lv-phone',
        ]);

        $black = $make('LV-B', 5);
        $white = $make('LV-W', 5);
        $red = $make('LV-R', 0);
        $this->answer($black, $colours['Black']);
        $this->answer($white, $colours['White']);
        $this->answer($red, $colours['Red']);

        return [$black, $white, $red];
    }

    public function test_the_basket_says_which_variation_and_switches_it_in_place(): void
    {
        [$black, $white, $red] = $this->group();
        $this->post('http://localhost/cart/add/lv-b', ['qty' => 2]);

        $html = $this->get('http://localhost/cart')->assertOk()->assertSee('LV Colour:')->getContent();
        $this->assertStringContainsString(route('cart.variation', (string) $black->id), $html);
        $this->assertMatchesRegularExpression('/<option value="'.$red->id.'"\s+disabled/', $html, 'a sold-out variation cannot be chosen');

        $this->withHeader('HX-Request', 'true')
            ->post('http://localhost/cart/items/'.$black->id.'/variation', ['product_id' => $white->id])
            ->assertOk()->assertHeader('HX-Trigger', 'torgash:cart-changed');

        $lines = app(CartManager::class)->reload()->raw();
        $this->assertSame([[(int) $white->id, 2]], array_map(fn ($line) => [(int) $line['product_id'], (int) $line['qty']], $lines), 'the same quantity, the other colour');

        // Not one of its siblings, or one that cannot be bought: nothing moves.
        $stranger = Product::create(['sku' => 'LV-X', 'name' => ['en' => 'X'], 'slug' => 'lv-x', 'price' => 1_00, 'stock' => 5, 'status' => 'active']);
        foreach ([$stranger, $red] as $refused) {
            $this->post('http://localhost/cart/items/'.$white->id.'/variation', ['product_id' => $refused->id]);
            $this->assertSame((int) $white->id, (int) app(CartManager::class)->reload()->raw()[0]['product_id']);
        }
    }

    public function test_the_order_keeps_the_variation_for_its_papers(): void
    {
        [$black] = $this->group();
        $this->post('http://localhost/cart/add/lv-b', ['qty' => 1]);

        $this->get('http://localhost/checkout')->assertOk()
            ->assertSee('href="'.route('catalog.product', 'lv-b').'"', false)
            ->assertSee('LV Colour:');

        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer', 'email' => 'guest@example.test',
            'address_line' => '1 Test Street', 'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ])->assertSessionHasNoErrors();

        $order = Order::latest('id')->firstOrFail();
        $this->assertSame([['label' => 'LV Colour', 'value' => 'Black']], $order->items[0]['variation']);

        // Renamed afterwards: the order still reads as it was bought.
        $black->featureAssignments()->first()->featureValue->update(['value' => ['en' => 'Midnight']]);
        $html = view('torgash.checkout::mail.order-details', ['order' => $order->fresh()])->render();
        $this->assertStringContainsString('LV Colour:', $html);
        $this->assertStringContainsString('Black', $html);
        $this->assertStringContainsString('LV-B', $html, 'the letter names the code');
    }
}
