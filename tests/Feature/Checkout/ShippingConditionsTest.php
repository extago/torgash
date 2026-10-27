<?php

namespace Tests\Feature\Checkout;

use App\Models\CustomerGroup;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Cart\CartManager;
use Torgash\Catalog\Models\Category;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Shipping\Location;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Quoter;
use Torgash\Shipping\ZoneMatcher;

/**
 * "Offer this method only when…".
 *
 * The zone answers WHERE; these answer for WHAT and for WHOM — the requirement
 * behind "courier only for furniture", "this rate is for wholesale customers",
 * "free delivery once they spend 500". Every condition must hold: a merchant
 * adding a row is narrowing what is offered, never widening it, which is the
 * only reading that stays predictable as the list grows.
 *
 * The facts a condition is tested against come from the CATALOGUE through a
 * hook — delivery knows what to ask, not what a product is — so this test also
 * pins that seam.
 */
class ShippingConditionsTest extends TestCase
{
    public function test_a_category_condition_hides_the_method_for_other_goods(): void
    {
        $furniture = Category::create(['name' => ['en' => 'Furniture'], 'slug' => 'furniture']);
        $sofa = $this->product('SOFA-1', 'sofa');
        $sofa->categories()->attach($furniture->id);
        $mug = $this->product('MUG-1', 'mug');

        $method = $this->method([['kind' => 'category', 'operator' => 'has_any', 'value' => ['ids' => [$furniture->id]]]]);

        $this->assertContains($method->id, $this->offeredFor($sofa));
        $this->assertNotContains($method->id, $this->offeredFor($mug));
    }

    public function test_every_product_must_qualify_when_the_rule_says_so(): void
    {
        $furniture = Category::create(['name' => ['en' => 'Furniture'], 'slug' => 'furniture-2']);
        $sofa = $this->product('SOFA-2', 'sofa-2');
        $sofa->categories()->attach($furniture->id);
        $mug = $this->product('MUG-2', 'mug-2');

        $method = $this->method([['kind' => 'category', 'operator' => 'all_from', 'value' => ['ids' => [$furniture->id]]]]);

        $this->assertContains($method->id, $this->offeredFor($sofa));
        // A cart of a sofa AND a mug is not "everything from furniture".
        $this->assertNotContains($method->id, $this->offeredFor($sofa, $mug));
    }

    public function test_a_characteristic_can_gate_the_method(): void
    {
        $fragile = $this->product('FRAGILE-1', 'fragile', ['Тип упаковки' => 'хрупкое']);
        $plain = $this->product('PLAIN-1', 'plain', ['Цвет' => 'синий']);

        $method = $this->method([[
            'kind' => 'attribute', 'operator' => 'attr_equals',
            'value' => ['key' => 'тип упаковки', 'match' => 'Хрупкое'],
        ]]);

        // Case and spelling of the key are the admin's, not the supplier's.
        $this->assertContains($method->id, $this->offeredFor($fragile));
        $this->assertNotContains($method->id, $this->offeredFor($plain));
    }

    public function test_the_order_total_is_measured_after_the_coupon(): void
    {
        $product = $this->product('BIG-1', 'big', price: 100_00);
        $method = $this->method([['kind' => 'cart_total', 'operator' => 'total_min', 'value' => ['amount' => 90_00]]]);

        $this->assertContains($method->id, $this->offeredFor($product));
    }

    public function test_a_customer_group_condition_needs_that_customer(): void
    {
        $wholesale = CustomerGroup::create(['name' => ['en' => 'Wholesale'], 'discount_percent' => 0]);
        $product = $this->product('GROUP-1', 'group-1');

        $method = $this->method([['kind' => 'customer_group', 'operator' => 'group_in', 'value' => ['ids' => [$wholesale->id]]]]);

        // A guest belongs to no group at all.
        $this->assertNotContains($method->id, $this->offeredFor($product));

        $user = User::create(['name' => 'Buyer', 'email' => 'wholesale@test', 'password' => bcrypt('x')]);
        $user->customer_group_id = $wholesale->id;
        $user->save();

        $this->actingAs($user);
        $this->assertContains($method->id, $this->offeredFor($product));
    }

    public function test_the_methods_list_spells_out_why_a_gated_method_can_be_absent(): void
    {
        // The confusion this fixes: a method with a rate still does not show in
        // checkout, and nothing on the methods screen said it had requirements.
        $furniture = Category::create(['name' => ['en' => 'Furniture'], 'slug' => 'furniture-admin']);
        $this->method([
            ['kind' => 'category', 'operator' => 'has_any', 'value' => ['ids' => [$furniture->id]]],
            ['kind' => 'weight_known', 'operator' => 'weight_known', 'value' => []],
            ['kind' => 'cart_total', 'operator' => 'total_min', 'value' => ['amount' => 500_00]],
        ]);

        $admin = User::create(['name' => 'A', 'email' => 'cond-admin@test', 'password' => bcrypt('x')]);
        $admin->assignRole(Role::findOrCreate('admin'));

        $this->actingAs($admin)
            ->get('http://localhost/admin/shipping/methods')
            ->assertOk()
            ->assertSee('Only when:')
            ->assertSee('the cart contains something from')
            // Resolved to a real name through the catalogue hook, not "1 selected".
            ->assertSee('Furniture')
            ->assertSee('every product has a weight')
            ->assertSee('the order is at least');
    }

    public function test_a_method_with_no_conditions_is_untouched(): void
    {
        $product = $this->product('PLAIN-2', 'plain-2');
        $method = $this->method([]);

        $this->assertContains($method->id, $this->offeredFor($product));
    }

    public function test_a_condition_nobody_can_evaluate_does_not_offer_the_method(): void
    {
        // No cart, no facts: a method restricted to wholesale customers must
        // not appear on a screen that has no idea who is looking.
        $method = $this->method([['kind' => 'coupon', 'operator' => 'coupon_any', 'value' => []]]);

        $offered = app(\Torgash\Shipping\ShippingOptions::class)
            ->for(new Location, new \Torgash\Checkout\Shipping\Package(subtotal: 10_00))
            ->pluck('method.id')
            ->all();

        $this->assertNotContains($method->id, $offered);
    }

    /** @param list<array<string, mixed>> $conditions */
    protected function method(array $conditions): ShippingMethod
    {
        static $n = 0;
        $method = ShippingMethod::create([
            'name' => ['en' => 'Conditional '.(++$n)], 'position' => 80 + $n, 'enabled' => true,
        ]);
        $method->rates()->create([
            'zone_id' => (new ZoneMatcher)->fallback()->id, 'base_rate' => 3_00, 'enabled' => true,
        ]);

        foreach ($conditions as $position => $condition) {
            $method->conditions()->create($condition + ['position' => $position]);
        }

        return $method;
    }

    /** @return list<int> ids of the methods offered for a cart of these products */
    protected function offeredFor(Product ...$products): array
    {
        // Built through the manager rather than by writing the session: the
        // cart is a row addressed by a token now, and its storage was never
        // part of the contract this test is about.
        $cart = app(CartManager::class);
        $cart->clear();

        foreach ($products as $product) {
            $cart->add($product->id, 1);
        }

        return app(Quoter::class)
            ->for($cart, new Location)
            ->options
            ->map(fn ($option) => $option->method->id)
            ->values()
            ->all();
    }

    /** @param array<string, string> $features */
    protected function product(string $sku, string $slug, array $features = [], int $price = 10_00): Product
    {
        return Product::create([
            'sku' => $sku,
            'name' => ['en' => $sku],
            'slug' => $slug,
            'price' => $price,
            'stock' => 10,
            'status' => Product::STATUS_ACTIVE,
            'features' => $features,
        ]);
    }
}
