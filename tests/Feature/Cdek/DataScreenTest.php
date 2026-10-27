<?php

namespace Tests\Feature\Cdek;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Cdek\Models\PlaceMap;
use Torgash\Locations\Models\Place;

/**
 * The carrier's data screen: it renders, a place can be bound by hand and
 * unbound, the sender city is picked from the directory, and the mapping
 * survives a round trip through its CSV.
 */
class DataScreenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin());
        DB::table('m_cdek_cities')->insert([
            'code' => 270, 'country' => 'RU', 'name' => 'Новосибирск', 'region' => 'Новосибирская область',
            'sub_region' => 'городской округ Новосибирск', 'latitude' => 55.03, 'longitude' => 82.92, 'synced_at' => now(),
        ]);
    }

    public function test_the_screen_renders_with_every_slice_and_sort(): void
    {
        $place = $this->place();

        $this->get('http://localhost/admin/cdek')->assertOk()->assertSee('Новосибирск');

        foreach (['manual', 'all'] as $show) {
            foreach (['place', 'region', 'population'] as $sort) {
                $this->get("http://localhost/admin/cdek?show={$show}&sort={$sort}")->assertOk();
            }
        }

        $this->assertNotNull($place);
    }

    public function test_a_place_is_bound_by_hand_and_unbound(): void
    {
        $place = $this->place();

        $this->get("http://localhost/admin/cdek/places/{$place->id}/candidates")
            ->assertOk()
            ->assertSee('городской округ Новосибирск');

        $this->post("http://localhost/admin/cdek/places/{$place->id}/bind", ['city_code' => 270])->assertOk();
        $this->assertSame(['city_code' => 270, 'matched_by' => 'manual'], PlaceMap::find($place->id)->only('city_code', 'matched_by'));

        $this->delete("http://localhost/admin/cdek/places/{$place->id}/bind")->assertOk();
        $this->assertNull(PlaceMap::find($place->id));
    }

    public function test_the_sender_city_is_picked_from_the_directory(): void
    {
        $this->get('http://localhost/admin/cdek/sender?q='.urlencode('новос'))->assertOk()->assertSee('Новосибирск');

        $this->post('http://localhost/admin/cdek/sender', ['city_code' => 270])->assertOk();
        $this->assertSame(270, (int) setting('torgash.cdek', 'sender_city_code'));

        $this->post('http://localhost/admin/cdek/sender', ['city_code' => 999999])->assertNotFound();
    }

    public function test_the_mapping_survives_its_csv(): void
    {
        $place = $this->place();
        PlaceMap::create(['place_id' => $place->id, 'city_code' => 270, 'matched_by' => 'manual', 'confidence' => 100]);

        $csv = $this->get('http://localhost/admin/cdek/map.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('cdek-test-ref', $csv);

        PlaceMap::query()->delete();
        $file = UploadedFile::fake()->createWithContent('map.csv', $csv);

        $this->post('http://localhost/admin/cdek/map', ['file' => $file])->assertRedirect();
        $this->assertSame(270, PlaceMap::find($place->id)?->city_code);
    }

    public function test_a_foreign_csv_is_refused(): void
    {
        $file = UploadedFile::fake()->createWithContent('np.csv', "place_ref,place_name,settlement_ref\nx,y,z\n");

        $this->post('http://localhost/admin/cdek/map', ['file' => $file])->assertSessionHasErrors('file');
    }

    protected function place(): Place
    {
        return Place::firstOrCreate(['ref' => 'cdek-test-ref'], [
            'country' => 'RU', 'name' => ['ru' => 'Новосибирск'], 'type' => 'settlement',
            'latitude' => 55.03, 'longitude' => 82.92, 'population' => 1_600_000, 'enabled' => true,
        ]);
    }

    protected function admin(): User
    {
        $user = User::firstWhere('email', 'cdek-admin@test');

        if (! $user) {
            $user = User::create(['name' => 'Admin', 'email' => 'cdek-admin@test', 'password' => bcrypt('x')]);
            $user->assignRole(Role::findOrCreate('admin'));
        }

        return $user;
    }
}
