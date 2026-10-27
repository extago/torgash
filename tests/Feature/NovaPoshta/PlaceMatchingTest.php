<?php

namespace Tests\Feature\NovaPoshta;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;
use Torgash\NovaPoshta\Models\PlaceMap;
use Torgash\NovaPoshta\Sync\Normalizer;
use Torgash\NovaPoshta\Sync\PlaceMatcher;

/**
 * Tying our registry to the carrier's directory.
 *
 * This is the module's one irreversible-looking decision: a wrong mapping
 * sends a customer's parcel to a village with the same name 400 km away, and
 * nothing anywhere errors. Ukraine has 80 places called Іванівка and 3 066
 * names that repeat, so every rule here exists because names alone cannot
 * identify a place.
 *
 * Measured on the real data (2026-09-01): 26 641 of 29 420 places matched,
 * and the bulk of the rest sits in Crimea and the occupied parts of Donetsk
 * and Luhansk, where the carrier has no rows at all.
 */
class PlaceMatchingTest extends TestCase
{
    public function test_namesakes_are_separated_by_oblast_and_raion(): void
    {
        $kyivska = $this->state('Київська область');
        $lvivska = $this->state('Львівська область');

        $here = $this->place('Іванівка', $kyivska, district: 'Бориспільський район', lat: 50.35, lon: 30.95);
        $there = $this->place('Іванівка', $lvivska, district: 'Стрийський район', lat: 49.26, lon: 23.85);

        $this->settlement('np-1-0000000000000000000000000000000', 'Іванівка', 'Київська', 'Бориспільський', 50.35, 30.95);
        $this->settlement('np-2-0000000000000000000000000000000', 'Іванівка', 'Львівська', 'Стрийський', 49.26, 23.85);

        (new PlaceMatcher)->run();

        $this->assertSame('np-1-0000000000000000000000000000000', PlaceMap::find($here->id)?->settlement_ref);
        $this->assertSame('np-2-0000000000000000000000000000000', PlaceMap::find($there->id)?->settlement_ref);
        $this->assertSame('name_area_district', PlaceMap::find($here->id)?->matched_by);
    }

    public function test_a_raion_the_two_sides_name_differently_still_matches_on_the_oblast(): void
    {
        // Real case: the carrier kept the pre-2020 raions, our pack has the
        // current ones. The oblast still agrees, and the name is unique in it.
        $state = $this->state('Полтавська область');
        $place = $this->place('Абазівка', $state, district: 'Полтавський район', lat: 49.6, lon: 34.33);
        $this->settlement('np-3-0000000000000000000000000000000', 'Абазівка', 'Полтавська', 'Зіньківський', 49.605, 34.335);

        (new PlaceMatcher)->run();

        $this->assertSame(['np-3-0000000000000000000000000000000', 'name_area'], [
            PlaceMap::find($place->id)?->settlement_ref,
            PlaceMap::find($place->id)?->matched_by,
        ]);
    }

    public function test_two_namesakes_in_one_oblast_are_split_by_coordinates(): void
    {
        $state = $this->state('Одеська область');
        $place = $this->place('Вишневе', $state, district: 'Ізмаїльський район', lat: 45.35, lon: 28.84);

        $this->settlement('np-near-0000000000000000000000000000', 'Вишневе', 'Одеська', 'Болградський', 45.36, 28.85);
        $this->settlement('np-far-00000000000000000000000000000', 'Вишневе', 'Одеська', 'Роздільнянський', 46.85, 30.08);

        (new PlaceMatcher)->run();

        $this->assertSame(['np-near-0000000000000000000000000000', 'coords'], [
            PlaceMap::find($place->id)?->settlement_ref,
            PlaceMap::find($place->id)?->matched_by,
        ]);
    }

    public function test_a_settlement_spelled_two_ways_is_still_one_candidate(): void
    {
        // The bug this pins cost a town of 19 000 its mapping: the carrier
        // spells it "П'ятихатки" in Ukrainian and "Пятихатки" in Russian, and
        // indexing both raw spellings filed ONE settlement under the same
        // normalised key twice. "Exactly one candidate" then never fired, and
        // the coordinate tie-break refused to pick a settlement 0.3 km away for
        // not being twice as close as its own duplicate.
        $state = $this->state('Дніпропетровська область');
        $place = $this->place("П'ятихатки", $state, district: "Кам'янський район", lat: 48.412457, lon: 33.705673);

        DB::table('m_np_settlements')->insert([
            'ref' => str_pad('np-pyat', 36, '0'),
            'kind' => 'settlement',
            'name_uk' => "П'ятихатки",
            'name_ru' => 'Пятихатки',
            'area' => 'Дніпропетровська',
            'region' => "П'ятихатський",
            'latitude' => 48.4139,
            'longitude' => 33.7079,
            'has_warehouse' => true,
            'synced_at' => now(),
        ]);

        (new PlaceMatcher)->run();

        $this->assertSame(str_pad('np-pyat', 36, '0'), PlaceMap::find($place->id)?->settlement_ref);
        // One candidate in the oblast, so it never needed the coordinates.
        $this->assertSame('name_area', PlaceMap::find($place->id)?->matched_by);
    }

    public function test_two_namesakes_the_same_distance_away_are_left_alone(): void
    {
        $state = $this->state('Сумська область');
        $place = $this->place('Надія', $state, district: 'Сумський район', lat: 50.90, lon: 34.80);

        // Both ~11 km away, on opposite sides: guessing here would be a coin
        // flip, and a coin flip is what sends a parcel to the wrong village.
        $this->settlement('np-a-0000000000000000000000000000000', 'Надія', 'Сумська', 'Роменський', 51.00, 34.80);
        $this->settlement('np-b-0000000000000000000000000000000', 'Надія', 'Сумська', 'Охтирський', 50.80, 34.80);

        (new PlaceMatcher)->run();

        $this->assertNull(PlaceMap::find($place->id));
    }

    public function test_the_same_village_listed_twice_is_not_a_tie(): void
    {
        // The carrier's directory really does carry a settlement twice, 0 km
        // apart (measured). Refusing to choose between a row and its own twin
        // helps nobody — but the row with branches is the one a parcel can be
        // addressed to.
        $state = $this->state('Житомирська область');
        $place = $this->place('Скочище', $state, district: 'Коростенський район', lat: 51.10, lon: 28.30);

        // Neither carries the raion our pack has — the carrier's are the
        // pre-2020 ones, which is the normal case and why this falls through
        // to name + oblast in the first place.
        $this->settlement($this->ref('twin-a'), 'Скочище', 'Житомирська', 'Овруцький', 51.101, 28.301, hasWarehouse: false);
        $this->settlement($this->ref('twin-b'), 'Скочище', 'Житомирська', 'Народицький', 51.102, 28.302, hasWarehouse: true);

        (new PlaceMatcher)->run();

        $this->assertSame($this->ref('twin-b'), PlaceMap::find($place->id)?->settlement_ref);
    }

    public function test_the_postal_index_settles_what_the_renamed_raions_cannot(): void
    {
        // Two namesakes in one oblast, both plausible by distance — but only
        // one carries an index inside this place's range.
        $state = $this->state('Запорізька область');
        $place = $this->place('Пологи', $state, district: 'Пологівський район', lat: 47.48, lon: 36.25);
        DB::table('m_locations_places')->where('id', $place->id)
            ->update(['postal_from' => '70600', 'postal_to' => '70609']);

        $this->settlement($this->ref('idx-a'), 'Пологи', 'Запорізька', 'Оріхівський', 47.50, 36.27, index: '70600');
        $this->settlement($this->ref('idx-b'), 'Пологи', 'Запорізька', 'Гуляйпільський', 47.46, 36.23, index: '71100');

        (new PlaceMatcher)->run();

        $this->assertSame([$this->ref('idx-a'), 'postal'], [
            PlaceMap::find($place->id)?->settlement_ref,
            PlaceMap::find($place->id)?->matched_by,
        ]);
    }

    public function test_a_place_the_carrier_does_not_know_stays_unmatched(): void
    {
        $state = $this->state('Автономна Республіка Крим');
        $place = $this->place('Ялта', $state, district: 'Ялтинський район', lat: 44.49, lon: 34.16);

        (new PlaceMatcher)->run();

        // Not an error: the carrier has no rows in Crimea at all, and a place
        // with no mapping is simply not offered this carrier.
        $this->assertNull(PlaceMap::find($place->id));
    }

    public function test_a_binding_made_by_hand_survives_the_next_run(): void
    {
        $state = $this->state('Київська область');
        $place = $this->place('Іванівка', $state, district: 'Бориспільський район', lat: 50.35, lon: 30.95);

        $this->settlement('np-auto-0000000000000000000000000000', 'Іванівка', 'Київська', 'Бориспільський', 50.35, 30.95);
        $this->settlement('np-chosen-00000000000000000000000000', 'Іванівка-Друга', 'Київська', 'Бориспільський', 50.36, 30.96);

        PlaceMap::create([
            'place_id' => $place->id,
            'settlement_ref' => 'np-chosen-00000000000000000000000000',
            'matched_by' => 'manual',
            'confidence' => 100,
            'checked_at' => now(),
        ]);

        (new PlaceMatcher)->run();

        $this->assertSame('np-chosen-00000000000000000000000000', PlaceMap::find($place->id)?->settlement_ref);
    }

    public function test_apostrophes_and_administrative_words_do_not_stop_a_match(): void
    {
        // Слов'янськ / Слов’янськ / Словянськ are one place; "область" and
        // "район" are words, not names.
        $this->assertSame(Normalizer::name("Слов'янськ"), Normalizer::name('Слов’янськ'));
        $this->assertSame('київська', Normalizer::name('Київська область'));
        $this->assertSame('полтавський', Normalizer::name('Полтавський р-н'));
        $this->assertSame('київ', Normalizer::name('Київ (Київська обл)'));
    }

    protected function state(string $name): State
    {
        return State::create([
            'country' => 'UA',
            'code' => substr(md5($name), 0, 4),
            'name' => ['uk' => $name],
            'enabled' => true,
        ]);
    }

    protected function place(string $name, State $state, string $district, float $lat, float $lon): Place
    {
        return Place::create([
            'country' => 'UA',
            'state_id' => $state->id,
            'name' => ['uk' => $name],
            'district' => ['uk' => $district],
            'type' => 'settlement',
            'latitude' => $lat,
            'longitude' => $lon,
            'enabled' => true,
        ]);
    }

    /** A carrier ref is char(36): a shorter one comes back space-padded. */
    protected function ref(string $name): string
    {
        return str_pad('np-'.$name.'-', 36, '0');
    }

    protected function settlement(
        string $ref,
        string $name,
        string $area,
        string $region,
        float $lat,
        float $lon,
        ?string $index = null,
        bool $hasWarehouse = true,
    ): void {
        DB::table('m_np_settlements')->insert([
            'ref' => $ref,
            'kind' => 'settlement',
            'name_uk' => $name,
            'name_ru' => $name,
            'area' => $area,
            'region' => $region,
            'latitude' => $lat,
            'longitude' => $lon,
            'index1' => $index,
            'has_warehouse' => $hasWarehouse,
            'synced_at' => now(),
        ]);
    }
}
