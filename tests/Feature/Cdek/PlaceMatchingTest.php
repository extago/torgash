<?php

namespace Tests\Feature\Cdek;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Cdek\Models\PlaceMap;
use Torgash\Cdek\Sync\Normalizer;
use Torgash\Cdek\Sync\PlaceMatcher;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;

/**
 * Tying our registry to CDEK's city codes. A wrong mapping sends a parcel to
 * a namesake village 400 km away and nothing anywhere errors, so every rule is
 * pinned by the case that made it necessary.
 *
 * Measured on the full Russian registry against the test directory
 * (2026-10-21): 134 289 of 144 802 places matched, 128 170 of them by name +
 * region + district.
 */
class PlaceMatchingTest extends TestCase
{
    public function test_an_old_city_with_wrong_coordinates_still_matches_by_its_district(): void
    {
        // Real rows: CDEK's Иркутск (code 281) is placed at 57.10, 106.36 —
        // 500 km from Irkutsk. Matching by coordinates first lost the city.
        $state = $this->state('Иркутская область');
        $place = $this->place('Иркутск', $state, 'городской округ Иркутск', 52.289, 104.280);
        $this->city(281, 'Иркутск', 'Иркутская область', 'городской округ Иркутск', 57.1003, 106.363);

        (new PlaceMatcher)->run();

        $this->assertSame([281, 'name_district'], [PlaceMap::find($place->id)?->city_code, PlaceMap::find($place->id)?->matched_by]);
    }

    public function test_namesakes_in_one_region_are_split_by_district(): void
    {
        // Real rows: two Одинцово in Moscow oblast, the big one with
        // coordinates 40 km off. "Муниципальный" and "городской" округ are the
        // same unit renamed — the word that matters is the name.
        $state = $this->state('Московская область');
        $big = $this->place('Одинцово', $state, 'Одинцовский городской округ', 55.678, 37.267);
        $small = $this->place('Одинцово', $state, 'городской округ Домодедово', 55.357, 37.737);

        $this->city(520, 'Одинцово', 'Московская область', 'Одинцовский муниципальный округ', 55.5179, 36.6919);
        $this->city(1178804, 'Одинцово', 'Московская область', 'городской округ Домодедово', 55.357, 37.737);

        (new PlaceMatcher)->run();

        $this->assertSame(520, PlaceMap::find($big->id)?->city_code);
        $this->assertSame(1178804, PlaceMap::find($small->id)?->city_code);
    }

    public function test_one_row_in_the_region_is_not_handed_to_every_namesake_of_ours(): void
    {
        // CDEK lists one Ивановка in the region, we have two: only the one its
        // coordinates agree with gets it.
        $state = $this->state('Амурская область');
        $near = $this->place('Ивановка', $state, 'Ивановский округ', 50.30, 128.10);
        $far = $this->place('Ивановка', $state, 'Тындинский округ', 55.10, 124.70);
        $this->city(9001, 'Ивановка', 'Амурская область', 'Ивановский муниципальный округ (старый)', 50.31, 128.11);

        (new PlaceMatcher)->run();

        $this->assertSame(9001, PlaceMap::find($near->id)?->city_code);
        $this->assertNull(PlaceMap::find($far->id), 'a namesake 500 km away is a different place');
    }

    public function test_a_region_the_sides_spell_differently_falls_back_to_coordinates(): void
    {
        $state = $this->state('Ханты-Мансийский автономный округ — Югра');
        $place = $this->place('Лангепас', $state, '', 61.25, 75.18);
        $this->city(9002, 'Лангепас', 'Югра', null, 61.252, 75.181);

        (new PlaceMatcher)->run();

        $this->assertSame([9002, 'coords'], [PlaceMap::find($place->id)?->city_code, PlaceMap::find($place->id)?->matched_by]);
    }

    public function test_a_binding_made_by_hand_survives_a_rerun_and_stale_automatic_ones_do_not(): void
    {
        $state = $this->state('Тверская область');
        $manual = $this->place('Ржев', $state, 'Ржевский округ', 56.26, 34.33);
        $gone = $this->place('Исчезнувшее', $state, 'Ржевский округ', 56.20, 34.20);

        PlaceMap::create(['place_id' => $manual->id, 'city_code' => 777, 'matched_by' => 'manual', 'confidence' => 100]);
        PlaceMap::create(['place_id' => $gone->id, 'city_code' => 778, 'matched_by' => 'coords', 'confidence' => 90, 'checked_at' => now()->subDay()]);
        $this->city(9003, 'Ржев', 'Тверская область', 'Ржевский муниципальный округ', 56.26, 34.33);

        (new PlaceMatcher)->run();

        $this->assertSame(777, PlaceMap::find($manual->id)?->city_code, 'a human decided that one');
        $this->assertNull(PlaceMap::find($gone->id), 'an automatic match nobody confirmed this run is dropped');
    }

    public function test_administrative_words_and_yo_do_not_stop_a_match(): void
    {
        $this->assertSame('татарстан', Normalizer::name('Республика Татарстан'));
        $this->assertSame('кемеровская', Normalizer::name('Кемеровская область - Кузбасс'));
        $this->assertSame('одинцовский', Normalizer::name('Одинцовский муниципальный округ'));
        $this->assertSame(Normalizer::name('Королёв'), Normalizer::name('Королев'));
        $this->assertSame('ростов-на-дону', Normalizer::name('г. Ростов-на-Дону'));
    }

    public function test_the_admins_picker_puts_the_same_region_first(): void
    {
        $state = $this->state('Новгородская область');
        $place = $this->place('Одинцово', $state, 'Пестовский округ', 58.71, 35.50);
        $this->city(9010, 'Одинцово', 'Тульская область', 'Ясногорский район', 54.67, 37.99);
        $this->city(9011, 'Одинцово', 'Новгородская область', 'Пестовский район', 58.71, 35.50);

        $candidates = (new PlaceMatcher)->candidatesFor($place->fresh());

        $this->assertSame(9011, $candidates[0]->code);
        $this->assertLessThan(1, $candidates[0]->distance_km);
    }

    protected function state(string $name): State
    {
        return State::create([
            'country' => 'RU',
            'code' => substr(md5($name), 0, 4),
            'name' => ['ru' => $name],
            'enabled' => true,
        ]);
    }

    protected function place(string $name, State $state, string $district, float $lat, float $lon): Place
    {
        return Place::create([
            'country' => 'RU',
            'state_id' => $state->id,
            'name' => ['ru' => $name],
            'district' => $district === '' ? null : ['ru' => $district],
            'type' => 'settlement',
            'latitude' => $lat,
            'longitude' => $lon,
            'enabled' => true,
        ]);
    }

    protected function city(int $code, string $name, string $region, ?string $subRegion, float $lat, float $lon): void
    {
        DB::table('m_cdek_cities')->insert([
            'code' => $code,
            'country' => 'RU',
            'name' => $name,
            'region' => $region,
            'sub_region' => $subRegion,
            'latitude' => $lat,
            'longitude' => $lon,
            'synced_at' => now(),
        ]);
    }
}
