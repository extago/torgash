<?php

namespace Tests\Feature\NovaPoshta;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Torgash\Checkout\Shipping\Location;
use Torgash\Checkout\Shipping\Package;
use Torgash\Locations\Models\Place;
use Torgash\Locations\Models\State;
use Torgash\NovaPoshta\Models\PlaceMap;
use Torgash\NovaPoshta\Models\Sync;
use Torgash\NovaPoshta\ServiceType;
use Torgash\NovaPoshta\Shipping\NearestTowns;
use Torgash\NovaPoshta\Shipping\NovaPoshtaProvider;
use Torgash\NovaPoshta\Shipping\Notice;
use Torgash\Shipping\Shipment;

/**
 * A village with no branch is the destination a Ukrainian shop meets every
 * week, and the one every shop answers badly: the carrier vanishes from the
 * list and the customer is left guessing whether it delivers at all.
 *
 * What is pinned: the carrier still does not offer an option it cannot
 * complete (an order with no branch is refused at placement anyway), but it
 * says so, and offers the nearest towns that DO have one — by real distance,
 * with what is known to be there, and as something the customer can act on.
 */
class NearestTownsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)->set('torgash.novaposhta', 'api_key', 'test-key');
        app(Notice::class)->forget();
    }

    public function test_the_nearest_towns_with_branches_come_back_closest_first(): void
    {
        // Kobeliachok, the owner's own village: no branch of its own.
        $village = $this->place('Кобелячок', 49.0680, 33.7999, hasWarehouse: false);
        $near = $this->place('Нова Галещина', 49.1600, 33.7100, hasWarehouse: true);
        $town = $this->place('Горішні Плавні', 49.0100, 33.6500, hasWarehouse: true);
        // Far enough away that it is a different trip, not a nearest branch.
        $this->place('Київ', 50.4501, 30.5234, hasWarehouse: true);
        // Near, but the carrier has nothing there either.
        $this->place('Салівка', 49.0700, 33.7100, hasWarehouse: false);

        $towns = app(NearestTowns::class)->around($village->id);

        // Both are about twelve kilometres away and the nearer one is the
        // one the arithmetic says, not the one that reads nearer on a map.
        $this->assertSame(['Нова Галещина', 'Горішні Плавні'], array_column($towns, 'name'));
        $this->assertSame([$near->id, $town->id], array_column($towns, 'place_id'));
        // Straight-line kilometres, the honest approximation for "how far".
        $this->assertGreaterThan(0, $towns[0]['km']);
        $this->assertLessThan($towns[1]['km'], $towns[0]['km']);
    }

    /** What is there is shown where it is known, never guessed at. */
    public function test_a_town_whose_branches_are_known_says_what_it_has(): void
    {
        $village = $this->place('Кобелячок', 49.0680, 33.7999, hasWarehouse: false);
        $near = $this->place('Нова Галещина', 49.1600, 33.7100, hasWarehouse: true);

        DB::table('m_np_warehouses')->insert([
            [
                'ref' => 'wh-1', 'settlement_ref' => 'ref-'.$near->id, 'number' => '1',
                'type_ref' => 'x', 'category' => 'branch', 'np_category' => 'Branch',
                'name_uk' => 'Відділення №1', 'name_ru' => '', 'short_uk' => '', 'short_ru' => '',
                'status' => 'Working', 'deny_to_select' => false, 'synced_at' => now(),
            ],
            [
                'ref' => 'wh-2', 'settlement_ref' => 'ref-'.$near->id, 'number' => '2',
                'type_ref' => 'y', 'category' => 'locker', 'np_category' => 'Postomat',
                'name_uk' => 'Поштомат №2', 'name_ru' => '', 'short_uk' => '', 'short_ru' => '',
                'status' => 'Working', 'deny_to_select' => false, 'synced_at' => now(),
            ],
        ]);

        $towns = app(NearestTowns::class)->around($village->id);

        $this->assertEqualsCanonicalizing(['branch', 'locker'], $towns[0]['kinds']);
    }

    /**
     * The carrier is still not offered where it cannot deliver — an option the
     * customer cannot complete is a dead end at the last step — but the page is
     * told why, and the nearest towns are fetched while they read.
     */
    public function test_a_village_with_no_branch_is_explained_instead_of_hidden(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response(['success' => true, 'data' => []])]);

        $village = $this->place('Кобелячок', 49.0680, 33.7999, hasWarehouse: false);
        $this->place('Нова Галещина', 49.1600, 33.7100, hasWarehouse: true);

        $offers = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $village->id),
            [Shipment::single(new Package(subtotal: 100_00))],
            ['service_type' => ServiceType::BRANCH],
        );

        // Nothing to offer, and the reason recorded for the page.
        $this->assertSame([], $offers);
        $this->assertSame($village->id, app(Notice::class)->place());

        $html = (string) hook_filter('shipping.quote.notices', '', []);

        $this->assertStringContainsString('Нова Галещина', $html);
        // …and the nearest few are fetched in the background, so the second
        // look says what is there and the picker is instant.
        Queue::assertPushed(\Torgash\NovaPoshta\Jobs\RefreshTownBranches::class);
    }

    /** With nothing near enough, there is nothing to suggest — but it still says so. */
    public function test_a_village_with_nothing_near_still_says_there_is_nothing_here(): void
    {
        $village = $this->place('Кобелячок', 49.0680, 33.7999, hasWarehouse: false);
        $this->place('Київ', 50.4501, 30.5234, hasWarehouse: true);

        $this->assertSame([], app(NearestTowns::class)->around($village->id));

        app(Notice::class)->noBranches($village->id);

        $html = (string) hook_filter('shipping.quote.notices', '', []);

        $this->assertStringContainsString('no branch or parcel locker', $html);
    }

    /**
     * A town whose branches we could not fetch is NOT sent to the next town:
     * it very likely has plenty, and the directions would be confident and
     * wrong. It used to be the case with no words at all — the method
     * disappeared for two minutes and came back.
     */
    public function test_a_town_we_could_not_ask_about_is_told_to_try_again(): void
    {
        Http::fake(['*' => Http::response(['success' => false, 'errors' => ['To many requests']], 200)]);

        $town = $this->place('Кобеляки', 49.1500, 34.2000, hasWarehouse: true);
        $this->place('Нова Галещина', 49.1600, 33.7100, hasWarehouse: true);

        // The carrier refused the last attempt, and nothing is cached.
        Sync::mark('warehouses:ref-'.$town->id.':failed', ['error' => 'To many requests']);

        $offers = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $town->id),
            [Shipment::single(new Package(subtotal: 100_00))],
            ['service_type' => ServiceType::BRANCH],
        );

        $this->assertSame([], $offers);
        $this->assertSame(Notice::UNKNOWN, app(Notice::class)->reason());

        $html = (string) hook_filter('shipping.quote.notices', '', []);

        $this->assertStringContainsString('Try again in a minute', $html);
        // The one thing it must not do: name a town to go to instead.
        $this->assertStringNotContainsString('Нова Галещина', $html);
    }

    /**
     * A town full of points and a parcel none of them takes is the case
     * nobody believes — every locker the carrier has stops at 20 kg — so it is
     * named rather than left to read as "no branches here".
     */
    public function test_a_parcel_too_heavy_for_the_town_says_so_and_offers_no_detour(): void
    {
        $town = $this->place('Кобеляки', 49.1500, 34.2000, hasWarehouse: true);
        $this->place('Нова Галещина', 49.1600, 33.7100, hasWarehouse: true);

        DB::table('m_np_warehouses')->insert([
            'ref' => 'wh-heavy', 'settlement_ref' => 'ref-'.$town->id, 'number' => '1',
            'type_ref' => 'x', 'category' => 'branch', 'np_category' => 'Branch',
            'name_uk' => 'Відділення №1', 'name_ru' => '', 'short_uk' => '', 'short_ru' => '',
            'status' => 'Working', 'deny_to_select' => false, 'synced_at' => now(),
            'total_max_weight_kg' => 20,
        ]);

        Sync::mark('warehouses:ref-'.$town->id, ['count' => 1]);

        $offers = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $town->id),
            [Shipment::single(new Package(weight: 21_000, subtotal: 100_00))],
            ['service_type' => ServiceType::BRANCH],
        );

        $this->assertSame([], $offers);
        $this->assertSame(Notice::TOO_HEAVY, app(Notice::class)->reason());

        $html = (string) hook_filter('shipping.quote.notices', '', []);

        $this->assertStringContainsString('21 kg parcel', $html);
        // The next town's lockers stop at 20 kg too.
        $this->assertStringNotContainsString('Нова Галещина', $html);
    }

    /** Two methods quote one destination; the more useful reason wins. */
    public function test_the_reason_worth_knowing_outranks_the_others(): void
    {
        $notice = app(Notice::class);

        $notice->noBranches(7, Notice::NONE);
        $notice->noBranches(7, Notice::TOO_HEAVY, ['kg' => 21]);

        $this->assertSame(Notice::TOO_HEAVY, $notice->reason());
        $this->assertSame(['kg' => 21], $notice->params());

        // …and a weaker one never overwrites it.
        $notice->noBranches(7, Notice::NONE);

        $this->assertSame(Notice::TOO_HEAVY, $notice->reason());
    }

    protected function place(string $name, float $lat, float $lon, bool $hasWarehouse): Place
    {
        $state = State::firstOrCreate(
            ['country' => 'UA', 'code' => 'PL'],
            ['name' => ['uk' => 'Полтавська область'], 'enabled' => true],
        );

        $place = Place::create([
            'country' => 'UA', 'state_id' => $state->id,
            'name' => ['uk' => $name], 'type' => 'settlement', 'enabled' => true,
            'latitude' => $lat, 'longitude' => $lon,
        ]);

        DB::table('m_np_settlements')->insert([
            'ref' => 'ref-'.$place->id, 'kind' => 'settlement',
            'name_uk' => $name, 'name_ru' => $name, 'area' => 'Полтавська',
            'has_warehouse' => $hasWarehouse, 'latitude' => $lat, 'longitude' => $lon,
            'synced_at' => now(),
        ]);

        PlaceMap::create([
            'place_id' => $place->id,
            'settlement_ref' => 'ref-'.$place->id,
            'matched_by' => 'manual',
            'confidence' => 100,
            'checked_at' => now(),
        ]);

        return $place;
    }
}
