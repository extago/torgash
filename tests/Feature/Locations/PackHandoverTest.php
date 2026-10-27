<?php

namespace Tests\Feature\Locations;

use Tests\TestCase;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;
use Torgash\Locations\PackImporter;

/**
 * Changing where the data comes from without changing what the shop points at.
 *
 * The Ukrainian pack moved from GeoNames to OpenStreetMap, which knows nothing
 * of GeoNames ids and numbers the regions differently. Done naively that is a
 * silent disaster: a second row for every town, the first one flagged as gone,
 * and every shipping zone still pointing at the row nobody updates any more —
 * so the customer is quoted from a town that stopped being maintained.
 *
 * What is pinned here is therefore identity, not data: the row a zone points at
 * has to be the row the new pack updates.
 */
class PackHandoverTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('app/testing-handover');

        if (! is_dir($this->dir)) {
            mkdir($this->dir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.'/*.csv') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    public function test_the_new_pack_takes_over_the_row_the_old_one_created(): void
    {
        $this->importGeonames([[703448, '12', 'Kyiv', 'Київ']]);

        $before = Place::where('ref', 'geonames:703448')->firstOrFail();

        $counts = $this->importOsm([['n26150422', 'geonames:703448', '30', 'Kyiv', 'Київ']]);

        $this->assertSame(1, Place::count(), 'the handover created a second Kyiv');
        $this->assertSame(0, $counts['created']);
        $this->assertSame(1, $counts['updated']);

        $after = Place::firstOrFail();
        // The id is the whole point: zone rules store it.
        $this->assertSame($before->id, $after->id, 'the town was replaced instead of taken over');
        $this->assertSame('osm:n26150422', $after->ref);
        $this->assertSame(0, Place::where('missing_in_source', true)->count());
    }

    public function test_a_town_the_new_pack_does_not_list_is_flagged_and_kept(): void
    {
        $this->importGeonames([
            [703448, '12', 'Kyiv', 'Київ'],
            [686966, '12', 'Abandoned', 'Покинуте'],
        ]);

        $this->importOsm([['n26150422', 'geonames:703448', '30', 'Kyiv', 'Київ']]);

        // An order or an address may already point at it, so it is flagged for
        // the admin to decide about — never deleted behind their back.
        $left = Place::where('ref', 'geonames:686966')->firstOrFail();
        $this->assertTrue($left->missing_in_source);
        $this->assertSame(2, Place::count());
    }

    public function test_a_region_is_renumbered_onto_another_regions_old_number(): void
    {
        // The collision that made this hard: ISO calls Dnipropetrovsk 12, and
        // 12 is the number GeoNames gave Kyiv City. Renumbering one at a time
        // walks into the other's row — it renamed a region in a first attempt.
        $this->importGeonames([
            [703448, '12', 'Kyiv', 'Київ'],
            [709930, '04', 'Dnipro', 'Дніпро'],
        ]);

        $kyivRegion = State::where('code', '12')->firstOrFail();
        $dniproRegion = State::where('code', '04')->firstOrFail();

        $this->importOsm([
            ['n26150422', 'geonames:703448', '30', 'Kyiv', 'Київ'],
            ['n337597818', 'geonames:709930', '12', 'Dnipro', 'Дніпро'],
        ], [
            ['30', 'geonames:'.$kyivRegion->geonameid, 'Kyiv City', 'Київ'],
            ['12', 'geonames:'.$dniproRegion->geonameid, 'Dnipropetrovsk Oblast', 'Дніпропетровська область'],
        ]);

        $this->assertSame(2, State::count(), 'renumbering left an orphan region behind');

        $kyivRegion->refresh();
        $dniproRegion->refresh();

        $this->assertSame('30', $kyivRegion->code);
        $this->assertSame('12', $dniproRegion->code, 'the region did not get the number it was promised');
        $this->assertSame('Дніпропетровська область', $dniproRegion->name['uk']);
        // Same rows, so a zone that says "this region" still means this region.
        $this->assertSame('Київ', $kyivRegion->name['uk']);
    }

    public function test_an_older_pack_without_refs_still_updates_its_own_rows(): void
    {
        // The GeoNames files predate refs entirely; the key they would be given
        // today is what the migration wrote into the rows they built.
        $this->importGeonames([[703448, '12', 'Kyiv', 'Київ']]);
        $counts = $this->importGeonames([[703448, '12', 'Kyiv', 'Київ']]);

        $this->assertSame(1, Place::count());
        $this->assertSame(1, $counts['updated']);
        $this->assertSame('geonames:703448', Place::firstOrFail()->ref);
    }

    public function test_a_row_with_no_name_at_all_is_skipped(): void
    {
        // OSM has a handful of place nodes with no name in any language. They
        // cannot be shown, searched or chosen — and the generated search column
        // calls jsonb_each_text on the name, so one of them used to abort the
        // import of the entire pack.
        $counts = $this->importOsm([
            ['n1', '', '63', 'Kharkiv', 'Харків'],
            ['n2', '', '63', '', ''],
        ]);

        $this->assertSame(1, Place::count());
        $this->assertSame(1, $counts['skipped']);
    }

    public function test_official_codes_travel_with_the_place(): void
    {
        $this->importOsm([['n1685829423', '', '63', 'Kharkiv', 'Харків']]);

        $place = Place::firstOrFail();
        // KATOTTG outlives the OSM node id and is what a carrier's own
        // directory is joined against.
        $this->assertSame('UA63120270010096107', $place->codes['katotth']);
    }

    /**
     * @param  list<array{0: int, 1: string, 2: string, 3: string}>  $rows
     */
    protected function importGeonames(array $rows): array
    {
        $states = $this->write('UA-states.csv', ['country', 'state_code', 'name_en', 'name_uk', 'name_ru', 'geonameid'],
            array_map(fn (string $code) => ['UA', $code, "Region {$code}", "Область {$code}", "Область {$code}", 900000 + (int) $code],
                array_unique(array_column($rows, 1))));

        $cities = $this->write('UA-cities.csv',
            ['geonameid', 'country', 'state_code', 'district', 'type', 'name_en', 'name_uk', 'name_ru',
                'latitude', 'longitude', 'population', 'postal_from', 'postal_to', 'aliases'],
            array_map(fn (array $row) => [$row[0], 'UA', $row[1], '', 'settlement', $row[2], $row[3], '',
                '', '', 0, '', '', ''], $rows));

        return app(PackImporter::class)->import($states, $cities);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string}>  $rows
     * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $regions
     */
    protected function importOsm(array $rows, array $regions = []): array
    {
        $regions = $regions ?: array_map(
            fn (string $code) => [$code, '', "Region {$code}", "Область {$code}"],
            array_unique(array_column($rows, 2)),
        );

        $states = $this->write('UA-osm-states.csv', ['country', 'state_code', 'ref', 'previous_ref', 'name_en', 'name_uk', 'name_ru'],
            array_map(fn (array $region) => ['UA', $region[0], 'osm:UA'.$region[0], $region[1], $region[2], $region[3], ''], $regions));

        $cities = $this->write('UA-osm-cities.csv',
            ['ref', 'previous_ref', 'geonameid', 'country', 'state_code', 'district', 'type',
                'name_en', 'name_uk', 'name_ru', 'latitude', 'longitude', 'population',
                'postal_from', 'postal_to', 'aliases', 'katotth', 'koatuu'],
            array_map(fn (array $row) => ['osm:'.$row[0], $row[1], '', 'UA', $row[2], '', 'settlement',
                $row[3], $row[4], '', '', '', 0, '', '', '', 'UA63120270010096107', ''], $rows));

        return app(PackImporter::class)->import($states, $cities);
    }

    /**
     * @param  list<string>  $header
     * @param  list<list<mixed>>  $rows
     */
    protected function write(string $name, array $header, array $rows): string
    {
        $path = $this->dir.'/'.$name;
        $handle = fopen($path, 'w');

        fputcsv($handle, $header, ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, $row, ';', '"', '');
        }

        fclose($handle);

        return $path;
    }
}
