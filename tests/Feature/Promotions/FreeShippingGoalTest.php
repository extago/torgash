<?php

namespace Tests\Feature\Promotions;

use App\Core\Settings\SettingsManager;
use Tests\TestCase;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Promotions\Models\Promotion;

/**
 * Free delivery as a goal (FreeShippingGoal, CartPricing::freeShipping()):
 * the basket's bar and the product page's note promise a sum only where a
 * free-shipping tier would really win at it, and say when it is won.
 */
class FreeShippingGoalTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'FSG-1', 'name' => ['en' => 'Toner'], 'slug' => 'toner',
            'price' => 1000_00, 'stock' => 20, 'status' => 'active',
        ]);
    }

    public function test_the_basket_counts_towards_the_threshold_and_says_when_it_is_won(): void
    {
        $this->freeFrom(3000_00);

        $this->get('http://localhost/product/toner')->assertOk()
            ->assertSee('Free delivery')
            ->assertSee('hx-trigger="torgash:cart-changed from:body"', false);

        $this->post('http://localhost/cart/add/toner', ['qty' => 1]);
        $this->get('http://localhost/cart')->assertOk()
            ->assertSee('Free delivery')
            ->assertSee('value="33"', false)
            ->assertSee('and delivery is free')
            ->assertDontSee('Activated');

        $this->post('http://localhost/cart/add/toner', ['qty' => 2]);
        $this->get('http://localhost/cart')->assertOk()
            ->assertSee('Activated')
            ->assertSee('Delivery of this order is free');
        $this->get('http://localhost/promotions/free-shipping?place=buy_box')->assertOk()->assertSee('Activated');
    }

    /** The nearest sum that really gives it — not one whose promotion wants a category the basket lacks. */
    public function test_only_a_threshold_that_would_win_is_promised(): void
    {
        $category = Category::create(['name' => ['en' => 'Cosmetics'], 'slug' => 'fsg-cosmetics', 'enabled' => true]);
        $this->freeFrom(2000_00, [['kind' => 'category', 'operator' => 'cat_has', 'ids' => [$category->id]]]);
        $this->freeFrom(5000_00);

        $this->post('http://localhost/cart/add/toner', ['qty' => 1]);

        $this->get('http://localhost/cart')->assertOk()
            ->assertSee('value="20"', false, 'measured against 5 000, the one this basket can have');
    }

    public function test_no_promotion_or_switched_off_no_bar(): void
    {
        $this->post('http://localhost/cart/add/toner', ['qty' => 1]);
        $this->get('http://localhost/cart')->assertOk()->assertDontSee('Free delivery');
        $this->get('http://localhost/product/toner')->assertOk()->assertDontSee('promotions/free-shipping', false);

        $this->freeFrom(3000_00);
        app(SettingsManager::class)->set('torgash.promotions', 'free_shipping_cart', false);
        app(SettingsManager::class)->set('torgash.promotions', 'free_shipping_product', 'none');

        $this->get('http://localhost/cart')->assertOk()->assertDontSee('Free delivery');
        $this->get('http://localhost/product/toner')->assertOk()->assertDontSee('promotions/free-shipping', false);
    }

    /** @param list<array<string, mixed>> $more */
    protected function freeFrom(int $threshold, array $more = []): Promotion
    {
        $promotion = Promotion::create(['name' => ['en' => 'Free from '.$threshold], 'zone' => 'cart', 'combine' => 'stack_add', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => $threshold], ...$more]],
            'discount_type' => 'free_shipping',
        ]);

        return $promotion;
    }
}
