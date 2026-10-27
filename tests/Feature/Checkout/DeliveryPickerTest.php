<?php

namespace Tests\Feature\Checkout;

use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\Zone;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;

/**
 * The customer's half of shipping zones: until this existed, only an admin on
 * the zone-test screen could see that a town is offered something different.
 *
 * What is pinned here is the round trip — a town named on the cart page is
 * still the town at checkout, and the town in the submitted form is the one
 * that gets priced — because both directions are silent when wrong: the
 * customer is shown a plausible price for the wrong place.
 */
class DeliveryPickerTest extends TestCase
{
    protected Place $kyiv;

    protected Zone $kyivZone;

    protected function setUp(): void
    {
        parent::setUp();

        Country::firstOrCreate(['code' => 'UA'], ['name' => ['en' => 'Ukraine']]);
        $this->kyiv = Place::create([
            'country' => 'UA',
            'name' => ['en' => 'Kyiv'],
            'geonameid' => 703448,
            'population' => 3_000_000,
            'source' => 'import',
        ]);

        $this->kyivZone = Zone::create(['name' => ['en' => 'Kyiv city'], 'position' => 0, 'enabled' => true]);
        $this->kyivZone->rules()->create(['kind' => 'place', 'value' => (string) $this->kyiv->id]);

        Product::create([
            'sku' => 'SHIP-1',
            'name' => ['en' => 'Shippable'],
            'slug' => 'shippable',
            'price' => 10_00,
            'stock' => 5,
            'status' => 'active',
        ]);

        $this->post('http://localhost/cart/add/shippable', ['qty' => 1])->assertOk();
    }

    public function test_a_town_named_on_the_cart_page_is_still_the_town_at_checkout(): void
    {
        $this->pricedInKyiv('Pickup from our shop');

        $this->post('http://localhost/cart/delivery', ['place_id' => $this->kyiv->id])
            ->assertOk()
            ->assertSee('Pickup from our shop');

        // No place_id in this request at all: the session is what carries it.
        $this->get('http://localhost/checkout')
            ->assertOk()
            ->assertSee('Pickup from our shop')
            ->assertSee('Kyiv city');
    }

    public function test_a_towns_own_zone_replaces_what_everywhere_offered(): void
    {
        // The narrower zone does not inherit the catch-all's rates, so naming
        // the town can take a method away as well as add one — which is how a
        // shop says "here, pickup only".
        $this->pricedInKyiv('Pickup from our shop');

        $this->get('http://localhost/checkout')->assertSee('Courier delivery');

        $this->post('http://localhost/cart/delivery', ['place_id' => $this->kyiv->id])->assertOk();

        $this->get('http://localhost/checkout')
            ->assertSee('Pickup from our shop')
            ->assertDontSee('Courier delivery');
    }

    public function test_the_town_in_the_submitted_form_beats_the_one_in_the_session(): void
    {
        $courier = ShippingMethod::where('name->en', 'Courier delivery')->firstOrFail();
        $courier->rates()->create(['zone_id' => $this->kyivZone->id, 'base_rate' => 7_77, 'enabled' => true]);

        $this->post('http://localhost/cart/delivery', ['place_id' => $this->kyiv->id])->assertOk();

        // Typed over, without picking from the list: this order is going
        // somewhere else, and Kyiv's rate must not follow it there.
        $this->post('http://localhost/checkout', $this->orderForm([
            'city' => 'Lviv',
            'place_id' => '',
            'shipping_method_id' => $courier->id,
        ]))->assertRedirect();

        $order = Order::latest('id')->firstOrFail();

        $this->assertSame('Lviv', $order->city);
        $this->assertNotSame(7_77, $order->shipping_cost, 'the session town priced an order going elsewhere');
    }

    public function test_an_address_no_zone_covers_is_offered_nothing_and_cannot_order(): void
    {
        // A shop that switched off its catch-all has genuinely said nothing
        // about this town; inventing a price for it would be worse than
        // admitting that.
        Zone::where('name->en', 'Everywhere')->update(['enabled' => false]);

        $this->post('http://localhost/cart/delivery', ['place_id' => $this->kyiv->id])
            ->assertOk()
            ->assertSee('We do not deliver there yet.');

        $this->get('http://localhost/checkout')
            ->assertOk()
            ->assertSee('We have no delivery method for that address yet.', false);

        $this->post('http://localhost/checkout', $this->orderForm([
            'shipping_method_id' => ShippingMethod::where('name->en', 'Courier delivery')->value('id'),
        ]))->assertSessionHasErrors('shipping_method_id');

        $this->assertSame(0, Order::count());
    }

    public function test_the_quote_fragment_brings_the_summary_with_it(): void
    {
        $this->pricedInKyiv('Pickup from our shop');

        // Both regions in one response: they sit in different columns, and a
        // method list quoted for one town beside a total for another is the
        // failure the out-of-band swap exists to prevent.
        $this->post('http://localhost/checkout/quote', ['place_id' => $this->kyiv->id])
            ->assertOk()
            ->assertSee('Pickup from our shop')
            ->assertSee('id="checkout-summary"', false)
            ->assertSee('hx-swap-oob', false);
    }

    public function test_the_storefront_suggests_only_towns_it_can_deliver_to(): void
    {
        $this->get('http://localhost/places/suggest?q=Kyi')->assertOk()->assertJsonCount(1, 'items');

        $this->kyiv->update(['enabled' => false]);

        $this->get('http://localhost/places/suggest?q=Kyi')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_a_suggestion_names_both_the_district_and_the_region(): void
    {
        // Not everyone can name their district; nearly everyone knows their
        // region, and one namesake list that shows only the half you do not
        // know is a list you cannot choose from.
        $region = State::create(['country' => 'UA', 'code' => '12', 'name' => ['en' => 'Kyiv region']]);
        $this->kyiv->update(['district' => ['en' => 'Brovary district'], 'state_id' => $region->id]);

        $this->get('http://localhost/places/suggest?q=Kyi')
            ->assertOk()
            ->assertJsonPath('items.0.context', 'Brovary district · Kyiv region');
    }

    public function test_the_suggestion_list_pages_past_its_limit(): void
    {
        // The limit used to be the whole answer, so the eleventh namesake was
        // unreachable however long the customer scrolled.
        $limit = (int) setting('torgash.locations', 'autocomplete_limit', 10);

        for ($i = 0; $i < $limit + 2; $i++) {
            Place::create([
                'country' => 'UA',
                'name' => ['en' => 'Ivanivka'],
                'district' => ['en' => 'District '.$i],
                'population' => 100 - $i,
                'source' => 'import',
            ]);
        }

        $this->get('http://localhost/places/suggest?q=Ivaniv')
            ->assertOk()
            ->assertJsonCount($limit, 'items')
            ->assertJsonPath('more', true);

        $this->get('http://localhost/places/suggest?q=Ivaniv&page=2')
            ->assertOk()
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('more', false);
    }

    public function test_a_country_alone_already_chooses_a_zone(): void
    {
        // Named before any town, and it has to count: this is how a shop
        // quotes "anywhere in Poland" without waiting for a Polish town.
        Country::firstOrCreate(['code' => 'PL'], ['name' => ['en' => 'Poland']]);
        $poland = Zone::create(['name' => ['en' => 'Poland'], 'position' => 0, 'enabled' => true]);
        $poland->rules()->create(['kind' => 'country', 'value' => 'PL']);

        $inPoland = ShippingMethod::create(['name' => ['en' => 'Polish courier'], 'position' => 91, 'enabled' => true]);
        $inPoland->rates()->create(['zone_id' => $poland->id, 'base_rate' => 3_00, 'enabled' => true]);

        $this->post('http://localhost/cart/delivery', ['country' => 'PL'])
            ->assertOk()
            ->assertSee('Polish courier')
            ->assertDontSee('Courier delivery');
    }

    public function test_the_order_records_the_country_it_was_sent_to(): void
    {
        // A city and a postcode are not an address once a shop serves two
        // countries, and the zone that priced the order cannot be
        // reconstructed from them either.
        // No place picked, so this is the catch-all zone the seeded courier is
        // priced in — the country is the only thing locating this order.
        $this->post('http://localhost/checkout', $this->orderForm([
            'place_id' => '',
            'shipping_method_id' => ShippingMethod::where('name->en', 'Courier delivery')->value('id'),
        ]))->assertRedirect();

        $this->assertSame('UA', Order::latest('id')->firstOrFail()->country);
    }

    /** A method that exists only in the Kyiv zone. */
    protected function pricedInKyiv(string $name): ShippingMethod
    {
        $method = ShippingMethod::create(['name' => ['en' => $name], 'position' => 90, 'enabled' => true]);
        $method->rates()->create(['zone_id' => $this->kyivZone->id, 'base_rate' => 0, 'enabled' => true]);

        return $method;
    }

    protected function orderForm(array $overrides = []): array
    {
        return $overrides + [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Kyiv',
            'country' => 'UA',
            'place_id' => (string) $this->kyiv->id,
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ];
    }
}
