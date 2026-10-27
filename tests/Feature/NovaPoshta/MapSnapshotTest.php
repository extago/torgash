<?php

namespace Tests\Feature\NovaPoshta;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;
use Torgash\NovaPoshta\Models\PlaceMap;
use Torgash\NovaPoshta\Sync\MapSnapshot;

/**
 * Taking the mapping away and putting it back.
 *
 * Everything else this module holds can be re-fetched from the carrier. The
 * mapping cannot: it is tens of thousands of decisions, some of them a person's,
 * and it is what disappears when the module is uninstalled or a re-sync goes
 * wrong. Keyed by the registry's own ref, so the file survives a re-import of
 * the locations pack — which is exactly the event most likely to destroy it.
 */
class MapSnapshotTest extends TestCase
{
    public function test_it_writes_a_row_per_mapping_keyed_by_the_places_ref(): void
    {
        $place = $this->place('osm:n1', 'Бабинці');
        $this->bind($place, 'manual');

        $rows = iterator_to_array((new MapSnapshot)->rows());

        $this->assertCount(1, $rows);
        $this->assertSame('osm:n1', $rows[0][0]);
        $this->assertSame('Бабинці', $rows[0][1]);
        $this->assertSame($this->ref($place), $rows[0][2]);
        $this->assertSame('manual', $rows[0][4]);
    }

    public function test_hand_made_places_stay_out_because_they_have_nothing_to_key_on(): void
    {
        // A place a human added to the registry has no pack ref, so a file row
        // for it could only come back attached to the wrong place.
        $place = $this->place(null, 'Наше село');
        $this->bind($place, 'manual');

        $this->assertCount(0, iterator_to_array((new MapSnapshot)->rows()));
    }

    public function test_restoring_finds_places_by_ref_even_with_new_ids(): void
    {
        $place = $this->place('osm:n2', 'Іванівка');

        $result = (new MapSnapshot)->restore([
            ['osm:n2', 'Іванівка', str_pad('np-restored', 36, '0'), '', 'manual', '100'],
            ['osm:nobody', 'Ніде', str_pad('np-orphan', 36, '0'), '', 'coords', '75'],
        ]);

        $this->assertSame(['restored' => 1, 'unknown' => 1], $result);
        $this->assertSame(str_pad('np-restored', 36, '0'), PlaceMap::find($place->id)?->settlement_ref);
        $this->assertSame('manual', PlaceMap::find($place->id)?->matched_by);
    }

    public function test_restoring_overwrites_an_automatic_mapping_with_the_saved_one(): void
    {
        $place = $this->place('osm:n3', 'Пологи');
        $this->bind($place, 'coords');

        (new MapSnapshot)->restore([
            ['osm:n3', 'Пологи', str_pad('np-from-file', 36, '0'), '', 'manual', '100'],
        ]);

        $this->assertSame(str_pad('np-from-file', 36, '0'), PlaceMap::find($place->id)?->settlement_ref);
    }

    public function test_the_download_is_a_csv_with_a_header(): void
    {
        $place = $this->place('osm:n4', 'Скочище');
        $this->bind($place, 'name_area');

        $response = $this->actingAs($this->admin())->get('http://localhost/admin/nova-poshta/map.csv');

        $response->assertOk();
        $body = $response->streamedContent();

        $this->assertStringContainsString('place_ref', $body);
        $this->assertStringContainsString('osm:n4', $body);
        // The BOM, without which Excel reads Cyrillic as mojibake.
        $this->assertStringStartsWith("\u{FEFF}", $body);
    }

    public function test_an_unrelated_csv_is_refused_rather_than_applied(): void
    {
        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('orders.csv', "order_id,total\n1,500\n");

        $this->actingAs($this->admin())
            ->post('http://localhost/admin/nova-poshta/map', ['file' => $file])
            ->assertSessionHasErrors('file');

        $this->assertSame(0, PlaceMap::count());
    }

    protected function place(?string $ref, string $name): Place
    {
        $state = State::firstOrCreate(
            ['country' => 'UA', 'code' => 'MS'],
            ['name' => ['uk' => 'Тестова область'], 'enabled' => true],
        );

        return Place::create([
            'country' => 'UA',
            'state_id' => $state->id,
            'ref' => $ref,
            'name' => ['uk' => $name, 'en' => 'Test'],
            'type' => 'settlement',
            'enabled' => true,
        ]);
    }

    protected function ref(Place $place): string
    {
        return str_pad('np-'.$place->id.'-', 36, '0');
    }

    protected function bind(Place $place, string $matchedBy): void
    {
        DB::table('m_np_settlements')->insertOrIgnore([
            'ref' => $this->ref($place),
            'kind' => 'settlement',
            'name_uk' => 'Пункт',
            'name_ru' => 'Пункт',
            'area' => 'Тестова',
            'has_warehouse' => true,
            'synced_at' => now(),
        ]);

        PlaceMap::create([
            'place_id' => $place->id,
            'settlement_ref' => $this->ref($place),
            'matched_by' => $matchedBy,
            'confidence' => 100,
            'checked_at' => now(),
        ]);
    }

    protected function admin(): User
    {
        $user = User::firstWhere('email', 'np-snapshot@test');

        if (! $user) {
            $user = User::create(['name' => 'Admin', 'email' => 'np-snapshot@test', 'password' => bcrypt('x')]);
            $user->assignRole(Role::findOrCreate('admin'));
        }

        return $user;
    }
}
