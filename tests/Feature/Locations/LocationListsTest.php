<?php

namespace Tests\Feature\Locations;

use App\Models\User;
use Tests\TestCase;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\State;

/**
 * The three location lists in the admin's common shape — and "apply to all
 * found" selecting exactly the rows the list shows. The countries' copy of the
 * filter matched a search by name only while the list matched the code too, so
 * "enable all found" for "ZQ" left out the country the screen was showing.
 */
class LocationListsTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_all_found_is_what_the_list_found_by_code_too(): void
    {
        $byCode = Country::create(['code' => 'ZQ', 'code3' => 'ZQQ', 'name' => ['en' => 'Nowhereland'], 'enabled' => false]);
        $byName = Country::create(['code' => 'ZX', 'code3' => 'ZXX', 'name' => ['en' => 'Zqville'], 'enabled' => false]);

        $this->actingAs($this->admin)->get('http://localhost/admin/locations/countries?q=zq')->assertOk()
            ->assertSee('Nowhereland')->assertSee('Zqville');

        $this->post('http://localhost/admin/locations/countries/bulk', ['action' => 'enable', 'all' => 1, 'q' => 'zq', 'status' => ''])
            ->assertRedirect();

        $this->assertTrue($byCode->fresh()->enabled, 'found by its code, enabled with the rest');
        $this->assertTrue($byName->fresh()->enabled);
    }

    public function test_a_country_switches_in_place_and_tabs_count(): void
    {
        $country = Country::create(['code' => 'ZW', 'code3' => 'ZWW', 'name' => ['en' => 'Switchland'], 'enabled' => false]);

        $this->actingAs($this->admin)->post("http://localhost/admin/locations/countries/{$country->id}/toggle")
            ->assertOk()->assertSee('country-'.$country->id, false);
        $this->assertTrue($country->fresh()->enabled);

        $this->get('http://localhost/admin/locations/countries?q=switchland&status=enabled')->assertOk()->assertSee('Switchland');
        $this->get('http://localhost/admin/locations/countries?q=switchland&status=disabled')->assertOk()->assertDontSee('Switchland');
    }

    public function test_regions_search_and_all_found_respects_the_status_tab(): void
    {
        $on = State::create(['country' => 'ZQ', 'code' => 'A1', 'name' => ['en' => 'Upper Zq'], 'enabled' => true]);
        $off = State::create(['country' => 'ZQ', 'code' => 'A2', 'name' => ['en' => 'Lower Zq'], 'enabled' => false]);

        $this->actingAs($this->admin)->get('http://localhost/admin/locations/regions?q=upper+zq')->assertOk()
            ->assertSee('Upper Zq')->assertDontSee('Lower Zq');

        // "Disable all found" under the Enabled tab reaches only the enabled one;
        // it used to ignore every filter but the country.
        $this->post('http://localhost/admin/locations/regions/bulk', ['action' => 'disable', 'all' => 1, 'q' => 'zq', 'country' => '', 'status' => 'enabled'])
            ->assertRedirect();
        $this->assertFalse($on->fresh()->enabled);
        $this->assertFalse($off->fresh()->enabled);
    }

    public function test_a_region_form_stays_open_after_save(): void
    {
        $state = State::create(['country' => 'ZQ', 'code' => 'B1', 'name' => ['en' => 'Stay Zq'], 'enabled' => true]);

        $this->actingAs($this->admin)->get("http://localhost/admin/locations/regions/{$state->id}/edit")->assertOk();
        $this->put("http://localhost/admin/locations/regions/{$state->id}", ['country' => 'ZQ', 'code' => 'B1', 'names' => ['en' => 'Stayed Zq'], 'enabled' => 1])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.locations.regions.edit', $state));
        $this->assertSame('Stayed Zq', $state->fresh()->name['en']);

        $this->put("http://localhost/admin/locations/regions/{$state->id}", ['country' => 'ZQ', 'code' => 'B1', 'names' => ['en' => 'Stayed Zq'], 'after_save' => 'close'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.locations.regions', ['country' => 'ZQ']));
    }
}
