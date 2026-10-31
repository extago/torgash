<?php

namespace Tests\Feature\Locations;

use App\Models\Storefront;
use App\Models\User;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Locations\Models\Place;

/**
 * Popular towns (Torgash\Locations\PopularPlaces): typed as ids on the
 * storefront's own form, offered as buttons in the "your town" window and
 * under the checkout's town field — each storefront its own list.
 */
class PopularPlacesTest extends TestCase
{
    protected Storefront $storefront;

    protected Place $dnipro;

    protected Place $kamianske;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storefront = Storefront::where('is_default', true)->firstOrFail();
        // A town is offered only in a country the shop serves (Place::available()).
        \Torgash\Locations\Models\Country::query()->updateOrCreate(['code' => 'UA'], ['name' => ['en' => 'Ukraine'], 'enabled' => true]);
        $this->dnipro = Place::create(['country' => 'UA', 'name' => ['en' => 'Popdnipro'], 'type' => 'settlement', 'enabled' => true]);
        $this->kamianske = Place::create(['country' => 'UA', 'name' => ['en' => 'Popkamianske'], 'type' => 'settlement', 'enabled' => true]);
    }

    protected function save(array $extra): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs(User::role('admin')->firstOrFail())->put('http://localhost/admin/storefronts/'.$this->storefront->id, [
            'code' => $this->storefront->code,
            'name' => $this->storefront->name,
            'domains' => implode(', ', $this->storefront->domains ?? []),
            'theme' => $this->storefront->theme ?: 'default',
            'default_locale' => $this->storefront->default_locale,
            'locales' => $this->storefront->locales,
            'default_currency' => $this->storefront->default_currency,
            'currencies' => $this->storefront->currencies,
            'popular_section' => '1',
        ] + $extra);
    }

    public function test_the_storefront_keeps_its_own_list_in_the_order_typed(): void
    {
        $this->save(['popular_places' => $this->kamianske->id.', '.$this->dnipro->id.','.$this->kamianske->id, 'popular_in_dialog' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame([$this->kamianske->id, $this->dnipro->id], setting('torgash.locations', 'popular_places', null, $this->storefront->id));
        $this->assertNull(setting('torgash.locations', 'popular_places'), 'the shop-wide value is untouched');

        $this->get(route('admin.storefronts.edit', $this->storefront))->assertOk()
            ->assertSee('name="popular_places"', false)
            ->assertSeeInOrder([$this->kamianske->id.' · Popkamianske', $this->dnipro->id.' · Popdnipro']);
    }

    public function test_an_id_no_town_has_is_refused(): void
    {
        $this->save(['popular_places' => $this->dnipro->id.', 99999999'])->assertSessionHasErrors('popular_places');

        $this->assertNull(setting('torgash.locations', 'popular_places', null, $this->storefront->id));
    }

    public function test_clearing_the_field_removes_the_list(): void
    {
        $this->save(['popular_places' => (string) $this->dnipro->id, 'popular_in_dialog' => '1']);
        $this->save(['popular_places' => '']);

        $this->assertNull(setting('torgash.locations', 'popular_places', null, $this->storefront->id));
        $this->assertFalse((bool) setting('torgash.locations', 'popular_in_dialog', false, $this->storefront->id));
    }

    public function test_the_window_offers_them_only_where_ticked(): void
    {
        $this->save(['popular_places' => $this->dnipro->id.','.$this->kamianske->id]);
        $this->get('http://localhost/location/dialog')->assertOk()->assertDontSee('pick('.$this->dnipro->id.')', false);

        $this->save(['popular_places' => $this->dnipro->id.','.$this->kamianske->id, 'popular_in_dialog' => '1']);
        $this->get('http://localhost/location/dialog')->assertOk()
            ->assertSeeInOrder(['pick('.$this->dnipro->id.')', 'Popdnipro', 'pick('.$this->kamianske->id.')', 'Popkamianske'], false);

        // A town switched off since is not offered.
        $this->kamianske->update(['enabled' => false]);
        $this->get('http://localhost/location/dialog')->assertDontSee('pick('.$this->kamianske->id.')', false);
    }

    public function test_checkout_offers_them_under_the_town_field(): void
    {
        $this->save(['popular_places' => (string) $this->dnipro->id, 'popular_at_checkout' => '1']);

        Product::create(['sku' => 'POP-1', 'name' => ['en' => 'Pop'], 'slug' => 'pop-1', 'price' => 10_00, 'stock' => 5, 'status' => 'active']);
        $this->post('http://localhost/cart/add/pop-1', ['qty' => 1]);

        $this->get('http://localhost/checkout')->assertOk()
            ->assertSee("\$dispatch('choose-place'", false)
            ->assertSee('\u0022id\u0022:'.$this->dnipro->id.',', false);
    }
}
