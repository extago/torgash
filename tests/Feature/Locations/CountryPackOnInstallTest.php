<?php

namespace Tests\Feature\Locations;

use App\Core\Settings\CoreSettings;
use App\Core\Settings\SettingsManager;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Locations\LocationsServiceProvider;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;

/**
 * A new shop had no country, no region and no town, and nothing said where
 * they come from (the first clean install, 2026-10-27). Installed into a
 * shop that knows its country, the module loads the world list and that
 * country's pack; the Import screen says where packs come from.
 */
class CountryPackOnInstallTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)->set('torgash.locations', 'packs_path', 'tests/fixtures/geo');
    }

    public function test_installing_into_a_shop_with_a_country_loads_its_pack(): void
    {
        app(SettingsManager::class)->set(CoreSettings::ID, 'default_country', 'ZZ');

        // The queue is sync under tests: the pack is in when install() returns.
        (new LocationsServiceProvider($this->app))->install();

        $this->assertNotNull(Country::where('code', 'ZZ')->first());
        $this->assertSame('Capitalia', Place::where('country', 'ZZ')->where('type', 'capital')->first()?->t('name', 'en'));
        $this->assertSame(2, Place::where('country', 'ZZ')->count());

        // Again: a country that has its places is not loaded twice.
        (new LocationsServiceProvider($this->app))->install();
        $this->assertSame(2, Place::where('country', 'ZZ')->count());
    }

    public function test_a_shop_with_no_country_gets_nothing(): void
    {
        app(SettingsManager::class)->set(CoreSettings::ID, 'default_country', '');

        (new LocationsServiceProvider($this->app))->install();

        $this->assertNull(Country::where('code', 'ZZ')->first());
        $this->assertSame(0, Place::where('country', 'ZZ')->count());
    }

    public function test_the_import_screen_says_where_the_towns_come_from(): void
    {
        $admin = User::create(['name' => 'Admin', 'email' => 'packs@test', 'password' => bcrypt('x')]);
        $admin->assignRole(Role::findOrCreate('admin'));

        $this->actingAs($admin)->get('http://localhost/admin/locations/import')->assertOk()
            ->assertSee('Where the towns come from')
            ->assertSee('download.geonames.org')
            ->assertSee('Test map · CC0');
    }
}
