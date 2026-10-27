<?php

namespace Tests\Feature\Locations;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;

/**
 * Deleting every place of a country is irreversible and large, so it is not
 * allowed to happen by a click — the code has to be typed. This pins that,
 * because a guard that only lives in a blade template is one refactor away
 * from being gone.
 */
class PurgeConfirmationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Country::create(['code' => 'UA', 'name' => ['en' => 'Ukraine']]);
        Place::create(['country' => 'UA', 'name' => ['en' => 'Kyiv'], 'geonameid' => 1, 'source' => 'import']);
        Place::create(['country' => 'UA', 'name' => ['en' => 'By hand'], 'source' => 'manual', 'edited' => true]);

        $this->actingAs(User::firstWhere('email', 'admin@torgash.local') ?? $this->admin());
    }

    public function test_the_confirmation_page_counts_what_cannot_be_restored(): void
    {
        $this->get('http://localhost/admin/locations/countries/UA/purge')
            ->assertOk()
            ->assertSee('cannot be undone', false)
            // The hand-added one is the whole reason this screen exists.
            ->assertSee('1 places were added by hand and exist nowhere else.', false);
    }

    public function test_a_wrong_code_deletes_nothing(): void
    {
        $this->from('http://localhost/admin/locations/countries/UA/purge')
            ->post('http://localhost/admin/locations/countries/purge', [
                'code' => 'UA',
                'confirm_code' => 'RU',
            ])
            ->assertRedirect('http://localhost/admin/locations/countries/UA/purge')
            ->assertSessionHasErrors('confirm_code');

        $this->assertSame(2, Place::where('country', 'UA')->count());
    }

    public function test_the_typed_code_deletes_the_country_data(): void
    {
        $this->post('http://localhost/admin/locations/countries/purge', [
            'code' => 'UA',
            'confirm_code' => 'ua',
        ])->assertRedirect();

        $this->assertSame(0, Place::where('country', 'UA')->count());
        $this->assertNotNull(Country::where('code', 'UA')->first(), 'the country itself was deleted');
    }

    protected function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => 'purge@test', 'password' => bcrypt('x')]);
        $user->assignRole(Role::findOrCreate('admin'));

        return $user;
    }
}
