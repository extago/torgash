<?php

namespace Tests\Feature\Locations;

use Tests\TestCase;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;
use Torgash\Locations\PackImporter;

/**
 * The promise the whole module rests on: an import is a diff, and what a human
 * changed is theirs. The failure this prevents is a real one the user hit in a
 * live shop — a city the dataset did not list, added by hand, wiped by the
 * next data update while the courier kept delivering there.
 */
class PackImportTest extends TestCase
{
    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('app/testing-packs');

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

    public function test_a_pack_creates_states_and_places(): void
    {
        $counts = $this->import([
            [703448, '12', 'Kyiv', 'Київ', 'Киев', 2952301],
            [709930, '04', 'Dnipro', 'Дніпро', 'Днепр', 968502],
        ]);

        $this->assertSame(2, $counts['created']);
        $this->assertSame(2, Place::count());
        $this->assertSame(2, State::count());

        $kyiv = Place::where('geonameid', 703448)->firstOrFail();
        $this->assertSame('Київ', $kyiv->name['uk'], 'the Ukrainian name must be keyed by its language code');
        $this->assertSame('Киев', $kyiv->name['ru']);
        $this->assertSame(2952301, $kyiv->population);
        $this->assertSame('UA', $kyiv->country);
    }

    public function test_reimporting_updates_instead_of_duplicating(): void
    {
        $this->import([[703448, '12', 'Kyiv', 'Київ', 'Киев', 2952301]]);
        $counts = $this->import([[703448, '12', 'Kyiv', 'Київ', 'Киев', 3000000]]);

        $this->assertSame(1, Place::count());
        $this->assertSame(1, $counts['updated']);
        $this->assertSame(3000000, Place::where('geonameid', 703448)->value('population'));
    }

    public function test_a_place_an_admin_edited_survives_the_next_import_untouched(): void
    {
        $this->import([[703448, '12', 'Kyiv', 'Київ', 'Киев', 2952301]]);

        $place = Place::where('geonameid', 703448)->firstOrFail();
        $place->update(['name' => ['en' => 'Kyiv', 'uk' => 'Київ (наш склад)'], 'edited' => true]);

        $counts = $this->import([[703448, '12', 'Kyiv', 'Київ', 'Киев', 999]]);

        $this->assertSame(1, $counts['skipped']);
        $this->assertSame(0, $counts['updated']);
        $this->assertSame('Київ (наш склад)', $place->fresh()->name['uk']);
        $this->assertSame(2952301, $place->fresh()->population, 'the pack overwrote an edited row');
    }

    public function test_a_place_added_by_hand_is_never_touched_and_never_deleted(): void
    {
        $this->import([[703448, '12', 'Kyiv', 'Київ', 'Киев', 2952301]]);

        // The Simferopol case: not in the data, delivered to anyway.
        $manual = Place::create([
            'country' => 'UA',
            'name' => ['en' => 'Our village', 'uk' => 'Наше село'],
            'source' => 'manual',
            'edited' => true,
            'enabled' => true,
        ]);

        $this->import([[703448, '12', 'Kyiv', 'Київ', 'Киев', 2952301]]);

        $this->assertNotNull($manual->fresh(), 'a hand-added place was deleted by an import');
        $this->assertFalse($manual->fresh()->missing_in_source, 'a hand-added place was flagged as missing from the pack');
    }

    public function test_a_place_the_new_pack_dropped_is_flagged_rather_than_deleted(): void
    {
        $this->import([
            [703448, '12', 'Kyiv', 'Київ', 'Киев', 2952301],
            [709930, '04', 'Dnipro', 'Дніпро', 'Днепр', 968502],
        ]);

        $counts = $this->import([[703448, '12', 'Kyiv', 'Київ', 'Киев', 2952301]]);

        $this->assertSame(1, $counts['flagged']);
        $this->assertSame(2, Place::count(), 'a place an order could point at was deleted');
        $this->assertTrue(Place::where('geonameid', 709930)->value('missing_in_source'));
    }

    public function test_search_finds_a_place_by_an_old_name_in_any_case(): void
    {
        $this->import([[709930, '04', 'Dnipro', 'Дніпро', 'Днепр', 968502, 'Дніпропетровськ|Екатеринослав']]);

        foreach (['дніпропетровськ', 'ЕКАТЕРИНОСЛАВ', 'днепр'] as $term) {
            $this->assertSame(
                1,
                Place::search($term)->count(),
                "searching for [{$term}] found nothing",
            );
        }
    }

    /**
     * A district is a translated field for the same reason a name is: it is
     * printed beside the name to tell two namesakes apart, so a reader who
     * cannot read it cannot tell them apart. A pack ships one
     * `district_<code>` column per language, keyed by the code itself.
     */
    public function test_a_district_is_stored_in_every_language_the_pack_carries(): void
    {
        $this->writePack(
            "ref;country;state_code;district_en;district_uk;type;name_en;name_uk;population
"
            ."osm:n1;UA;32;Brovary District;Броварський район;settlement;Shevchenkove;Шевченкове;300
",
        );

        $place = Place::where('ref', 'osm:n1')->firstOrFail();

        $this->assertSame('Броварський район', $place->district['uk']);
        $this->assertSame('Brovary District', $place->district['en']);
        $this->assertSame('Шевченкове (Броварський район)', $place->label('uk'));
        $this->assertSame('Shevchenkove (Brovary District)', $place->label('en'));
    }

    /**
     * A pack from before districts had languages has one bare `district`
     * column and no way to say what is in it — "Броварський район" and
     * "Броварский район" differ by a letter. So it is filed under "und"
     * (undetermined) rather than guessed at, and nothing is lost: t() falls
     * through to the first available value, so every locale still renders it.
     */
    public function test_a_district_from_an_older_pack_is_filed_as_undetermined(): void
    {
        $this->writePack(
            "ref;country;state_code;district;type;name_en;name_uk;population
"
            ."osm:n1;UA;32;Броварський район;settlement;Shevchenkove;Шевченкове;300
",
        );

        $place = Place::where('ref', 'osm:n1')->firstOrFail();

        $this->assertSame(['und' => 'Броварський район'], $place->district);
        $this->assertSame('Shevchenkove (Броварський район)', $place->label('en'),
            'an undetermined district must still render, or the older pack would lose its disambiguator');
    }

    /** And the guess-free placeholder goes away the moment a pack does name its languages. */
    public function test_a_newer_pack_replaces_an_undetermined_district(): void
    {
        $this->writePack(
            "ref;country;state_code;district;type;name_en;name_uk;population
"
            ."osm:n1;UA;32;Броварський район;settlement;Shevchenkove;Шевченкове;300
",
        );

        $this->writePack(
            "ref;country;state_code;district_en;district_uk;type;name_en;name_uk;population
"
            ."osm:n1;UA;32;Brovary District;Броварський район;settlement;Shevchenkove;Шевченкове;300
",
        );

        $this->assertSame(
            ['en' => 'Brovary District', 'uk' => 'Броварський район'],
            Place::where('ref', 'osm:n1')->firstOrFail()->district,
        );
    }

    /** A place with no district at all keeps a null column, not an empty object. */
    public function test_a_place_without_a_district_stores_null(): void
    {
        $this->writePack(
            "ref;country;state_code;district_en;district_uk;type;name_en;name_uk;population
"
            ."osm:n1;UA;32;;;settlement;Shevchenkove;Шевченкове;300
",
        );

        $this->assertNull(Place::where('ref', 'osm:n1')->firstOrFail()->district);
    }

    /** Writes a cities file verbatim, so a test can pin the exact column shape. */
    protected function writePack(string $cities): void
    {
        $statesFile = $this->dir.'/UA-states.csv';
        $citiesFile = $this->dir.'/UA-cities.csv';

        file_put_contents($statesFile, "country;state_code;name_en;name_uk
UA;32;Kyiv Oblast;Київська область
");
        file_put_contents($citiesFile, $cities);

        app(PackImporter::class)->import($statesFile, $citiesFile);
    }

    /**
     * @param  list<array{0:int,1:string,2:string,3:string,4:string,5:int,6?:string}>  $rows
     * @return array<string, int>
     */
    protected function import(array $rows): array
    {
        $states = $this->dir.'/UA-states.csv';
        $cities = $this->dir.'/UA-cities.csv';

        $handle = fopen($states, 'w');
        fputcsv($handle, ['country', 'state_code', 'name_en', 'name_uk', 'name_ru', 'geonameid'], ';', '"', '');

        foreach (array_unique(array_column($rows, 1)) as $code) {
            fputcsv($handle, ['UA', $code, "Region {$code}", "Область {$code}", "Область {$code}", ''], ';', '"', '');
        }

        fclose($handle);

        $handle = fopen($cities, 'w');
        fputcsv($handle, [
            'geonameid', 'country', 'state_code', 'district', 'type',
            'name_en', 'name_uk', 'name_ru', 'latitude', 'longitude',
            'population', 'postal_from', 'postal_to', 'aliases',
        ], ';', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row[0], 'UA', $row[1], '', 'settlement',
                $row[2], $row[3], $row[4], '', '',
                $row[5], '', '', $row[6] ?? '',
            ], ';', '"', '');
        }

        fclose($handle);

        return app(PackImporter::class)->import($states, $cities);
    }
}
