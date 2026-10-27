<?php

namespace Tests\Feature\Locations;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;

/**
 * A pack classifies every row, and some classes are nobody's delivery address:
 * a "city district" is a neighbourhood of a bigger city, and offering eighty of
 * them under a city's name is how a customer picks the wrong one.
 *
 * The shop switches those types off. What is pinned here is that it is a
 * FILTER and not a purge — the rows keep existing, keep their zones and stay
 * findable in the admin — because the tempting alternative (bulk-disabling a
 * hundred thousand rows) also marks them all as edited, and the next pack
 * import would then skip every one of them for good.
 */
class HiddenTypesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Country::firstOrCreate(['code' => 'UA'], ['name' => ['en' => 'Ukraine']]);

        Place::create([
            'country' => 'UA',
            'name' => ['en' => 'Kyiv'],
            'type' => 'capital',
            'geonameid' => 703448,
            'population' => 3_000_000,
            'source' => 'import',
        ]);

        Place::create([
            'country' => 'UA',
            'name' => ['en' => 'Kyivskyi raion'],
            'type' => 'city_district',
            'geonameid' => 703449,
            'population' => 40_000,
            'source' => 'import',
        ]);
    }

    public function test_a_hidden_type_leaves_the_customers_field_but_not_the_registry(): void
    {
        $this->get('http://localhost/places/suggest?q=Kyiv')->assertOk()->assertJsonCount(2, 'items');

        $this->hide('city_district');

        $this->get('http://localhost/places/suggest?q=Kyiv')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.name', 'Kyiv');

        $this->assertSame(2, Place::count(), 'hiding a type deleted rows');
        $this->assertSame(2, Place::where('enabled', true)->count(), 'hiding a type switched rows off');
        $this->assertSame(0, Place::where('edited', true)->count(), 'hiding a type marked rows as edited');
    }

    public function test_the_admin_is_still_offered_a_type_the_customer_is_not(): void
    {
        // A zone can be built for a place that is not on sale today, so the
        // admin picker deliberately searches everything.
        $this->hide('city_district');
        $this->actingAs($this->admin());

        $this->get('http://localhost/admin/locations/suggest?q=Kyiv')
            ->assertOk()
            ->assertJsonCount(2, 'items');
    }

    public function test_the_places_list_can_be_filtered_by_type(): void
    {
        $this->actingAs($this->admin());

        $this->get('http://localhost/admin/locations?type=city_district')
            ->assertOk()
            ->assertSee('Kyivskyi raion')
            ->assertDontSee('>Kyiv<', false);
    }

    public function test_the_places_list_can_be_searched(): void
    {
        // The search orders by size, and the tab counts are aggregates over
        // the same filters: an ORDER BY left beside them was a 500.
        $this->actingAs($this->admin());

        $this->get('http://localhost/admin/locations?q=kyivskyi')
            ->assertOk()
            ->assertSee('Kyivskyi raion');
    }

    public function test_only_declared_type_codes_are_stored(): void
    {
        $this->actingAs($this->admin());

        $this->post('http://localhost/admin/settings/torgash.locations', [
            'packs_path' => 'data/locations',
            'autocomplete_limit' => 10,
            'hidden_types' => ['city_district', 'not_a_type'],
        ])->assertRedirect();

        $this->assertSame(
            ['city_district'],
            setting('torgash.locations', 'hidden_types'),
            'a code the manifest never declared was stored as a type to hide',
        );
    }

    protected function hide(string ...$types): void
    {
        app(SettingsManager::class)->set('torgash.locations', 'hidden_types', $types);
    }

    protected function admin(): User
    {
        $user = User::firstWhere('email', 'hidden-types@test');

        if (! $user) {
            $user = User::create(['name' => 'Admin', 'email' => 'hidden-types@test', 'password' => bcrypt('x')]);
            $user->assignRole(Role::findOrCreate('admin'));
        }

        return $user;
    }
}
