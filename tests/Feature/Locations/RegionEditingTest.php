<?php

namespace Tests\Feature\Locations;

use Tests\TestCase;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;
use Torgash\Locations\PackImporter;

/**
 * Regions can be made and removed by hand, with the same two promises places
 * have: what a person touched is theirs, and removing a container never
 * removes what was in it.
 */
class RegionEditingTest extends TestCase
{
    public function test_deleting_a_region_keeps_its_places(): void
    {
        $state = State::create(['country' => 'UA', 'code' => 'TEST', 'name' => ['en' => 'Test'], 'source' => 'manual']);
        $place = Place::create(['country' => 'UA', 'state_id' => $state->id, 'name' => ['en' => 'Town'], 'geonameid' => 5]);

        $state->delete();

        $this->assertNotNull($place->fresh(), 'deleting a region deleted its places');
        $this->assertNull($place->fresh()->state_id, 'the place kept a link to a region that no longer exists');
    }

    public function test_a_region_edited_by_hand_survives_the_next_import(): void
    {
        $dir = storage_path('app/testing-regions');

        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $states = "{$dir}/UA-states.csv";
        $cities = "{$dir}/UA-cities.csv";

        file_put_contents($states, "country;state_code;name_en;name_uk\nUA;12;Kyiv Oblast;Київська область\n");
        file_put_contents(
            $cities,
            "geonameid;country;state_code;name_en;name_uk\n703448;UA;12;Kyiv;Київ\n",
        );

        app(PackImporter::class)->import($states, $cities);

        $state = State::where('code', '12')->firstOrFail();
        $state->update(['name' => ['en' => 'Our own name'], 'edited' => true]);

        app(PackImporter::class)->import($states, $cities);

        $this->assertSame('Our own name', $state->fresh()->name['en'], 'the pack overwrote a region the admin renamed');
        // The place still lands in it: only the name is protected, not the link.
        $this->assertSame($state->id, Place::where('geonameid', 703448)->value('state_id'));

        unlink($states);
        unlink($cities);
    }
}
