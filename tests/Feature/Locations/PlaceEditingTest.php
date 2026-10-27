<?php

namespace Tests\Feature\Locations;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Locations\Models\Place;

/**
 * Editing a place by hand, with the district as a translated field.
 *
 * Worth its own test because of what a silent failure here would cost: saving
 * also marks the row as EDITED, which is the flag that tells every future
 * import to leave it alone. A form that quietly dropped the district would not
 * just lose it once — it would lose it permanently, and re-importing the pack
 * (the obvious fix) would be exactly the thing that no longer touches the row.
 */
class PlaceEditingTest extends TestCase
{
    public function test_a_district_can_be_written_in_every_language(): void
    {
        $place = Place::create([
            'ref' => 'osm:n1',
            'country' => 'UA',
            'name' => ['en' => 'Shevchenkove', 'uk' => 'Шевченкове'],
            'district' => ['en' => 'Brovary District', 'uk' => 'Броварський район'],
            'source' => 'import',
        ]);

        $this->actingAs($this->admin());

        $this->put("http://localhost/admin/locations/{$place->id}", [
            'names' => ['en' => 'Shevchenkove', 'uk' => 'Шевченкове'],
            'districts' => ['en' => 'Bashtanka District', 'uk' => 'Баштанський район'],
            'country' => 'UA',
            'type' => 'settlement',
            'enabled' => '1',
        ])->assertRedirect();

        $place->refresh();

        $this->assertSame(
            ['en' => 'Bashtanka District', 'uk' => 'Баштанський район'],
            $place->district,
        );
        $this->assertTrue($place->edited, 'a hand-edited place must be protected from the next import');
    }

    /** An emptied box is a district that is gone, not one stored as "". */
    public function test_clearing_every_box_clears_the_district(): void
    {
        $place = Place::create([
            'ref' => 'osm:n2',
            'country' => 'UA',
            'name' => ['en' => 'Shevchenkove'],
            'district' => ['en' => 'Brovary District'],
            'source' => 'import',
        ]);

        $this->actingAs($this->admin());

        $this->put("http://localhost/admin/locations/{$place->id}", [
            'names' => ['en' => 'Shevchenkove'],
            'districts' => ['en' => '', 'uk' => ''],
            'country' => 'UA',
            'type' => 'settlement',
        ])->assertRedirect();

        $this->assertNull($place->refresh()->district);
    }

    /**
     * A pack from before districts had languages leaves an "und" key behind.
     * The form has to offer it: it is a real value, and a form that only shows
     * the shop's own languages would delete it on the first save.
     */
    public function test_the_form_offers_a_language_only_the_data_knows_about(): void
    {
        $place = Place::create([
            'ref' => 'osm:n3',
            'country' => 'UA',
            'name' => ['en' => 'Shevchenkove'],
            'district' => ['und' => 'Броварський район'],
            'source' => 'import',
        ]);

        $this->actingAs($this->admin());

        $this->get("http://localhost/admin/locations/{$place->id}/edit")
            ->assertOk()
            ->assertSee('districts[und]', false)
            ->assertSee('Броварський район', false);
    }

    protected function admin(): User
    {
        $user = User::firstWhere('email', 'place-editing@test');

        if (! $user) {
            $user = User::create(['name' => 'Admin', 'email' => 'place-editing@test', 'password' => bcrypt('x')]);
            $user->assignRole(Role::findOrCreate('admin'));
        }

        return $user;
    }
}
