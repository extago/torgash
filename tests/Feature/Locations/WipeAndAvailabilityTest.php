<?php

namespace Tests\Feature\Locations;

use Tests\TestCase;
use Torgash\Locations\Models\Country;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;
use Torgash\Locations\PackImporter;

/**
 * The destructive half, and the cascade that decides what a customer may pick.
 *
 * Both are worth pinning precisely because they are easy to get subtly wrong:
 * a wipe that spares hand-added rows would not be a wipe (and the whole point
 * is starting over), while a cascade that forgets one level makes "we don't
 * ship to this country" a lie the checkout still offers.
 */
class WipeAndAvailabilityTest extends TestCase
{
    public function test_wiping_a_country_removes_even_the_places_added_by_hand(): void
    {
        $state = State::create(['country' => 'UA', 'code' => '12', 'name' => ['en' => 'Kyiv Oblast']]);

        Place::create(['country' => 'UA', 'state_id' => $state->id, 'name' => ['en' => 'Imported'], 'geonameid' => 1, 'source' => 'import']);
        Place::create(['country' => 'UA', 'name' => ['en' => 'By hand'], 'source' => 'manual', 'edited' => true]);
        $other = Place::create(['country' => 'PL', 'name' => ['en' => 'Warsaw'], 'geonameid' => 2, 'source' => 'import']);

        $deleted = app(PackImporter::class)->wipe('UA');

        $this->assertSame(2, $deleted);
        $this->assertSame(0, Place::where('country', 'UA')->count());
        $this->assertSame(0, State::where('country', 'UA')->count());
        $this->assertNotNull($other->fresh(), 'wiping one country took another country with it');
    }

    public function test_a_wiping_import_starts_from_nothing(): void
    {
        Place::create(['country' => 'UA', 'name' => ['en' => 'Left over'], 'source' => 'manual', 'edited' => true]);

        $counts = $this->import(replace: true);

        $this->assertSame(1, $counts['deleted']);
        $this->assertSame(1, $counts['created']);
        $this->assertSame(1, Place::count());
        $this->assertSame('Kyiv', Place::first()->name['en']);
    }

    public function test_a_normal_import_leaves_the_hand_added_place_alone(): void
    {
        Place::create(['country' => 'UA', 'name' => ['en' => 'Left over'], 'source' => 'manual', 'edited' => true]);

        $counts = $this->import(replace: false);

        $this->assertSame(0, $counts['deleted']);
        $this->assertSame(2, Place::count());
    }

    public function test_a_place_is_unavailable_when_its_country_is_off(): void
    {
        [$country, $state, $place] = $this->hierarchy();

        $this->assertSame(1, Place::available()->count());

        $country->update(['enabled' => false]);
        $this->assertSame(0, Place::available()->count(), 'a disabled country still offered its towns');

        $country->update(['enabled' => true]);
        $state->update(['enabled' => false]);
        $this->assertSame(0, Place::available()->count(), 'a disabled region still offered its towns');

        $state->update(['enabled' => true]);
        $place->update(['enabled' => false]);
        $this->assertSame(0, Place::available()->count());
    }

    public function test_a_place_of_an_unlisted_country_is_not_offered(): void
    {
        // No country row at all — the pack was imported before the world list,
        // or for a country the shop removed. Treated as switched off rather
        // than as switched on by omission.
        Place::create(['country' => 'ZZ', 'name' => ['en' => 'Nowhere'], 'geonameid' => 9, 'source' => 'import']);

        $this->assertSame(0, Place::available()->count());
    }

    /** @return array{0: Country, 1: State, 2: Place} */
    protected function hierarchy(): array
    {
        $country = Country::create(['code' => 'UA', 'name' => ['en' => 'Ukraine'], 'enabled' => true]);
        $state = State::create(['country' => 'UA', 'code' => '12', 'name' => ['en' => 'Kyiv Oblast'], 'enabled' => true]);
        $place = Place::create([
            'country' => 'UA',
            'state_id' => $state->id,
            'name' => ['en' => 'Kyiv'],
            'geonameid' => 703448,
            'source' => 'import',
            'enabled' => true,
        ]);

        return [$country, $state, $place];
    }

    /** @return array<string, int> */
    protected function import(bool $replace): array
    {
        $dir = storage_path('app/testing-wipe');

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $states = "{$dir}/UA-states.csv";
        $cities = "{$dir}/UA-cities.csv";

        file_put_contents($states, "country;state_code;name_en;name_uk\nUA;12;Kyiv Oblast;Київська область\n");
        file_put_contents(
            $cities,
            "geonameid;country;state_code;district;type;name_en;name_uk;latitude;longitude;population;postal_from;postal_to;aliases\n"
            ."703448;UA;12;;capital;Kyiv;Київ;50.45;30.52;2952301;;;\n",
        );

        $counts = app(PackImporter::class)->import($states, $cities, null, $replace);

        unlink($states);
        unlink($cities);

        return $counts;
    }
}
