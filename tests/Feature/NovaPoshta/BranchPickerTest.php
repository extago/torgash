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
use Torgash\NovaPoshta\Http\BranchesController;
use Torgash\NovaPoshta\Jobs\RefreshTownBranches;
use Torgash\NovaPoshta\Jobs\SyncWarehouses;
use Torgash\NovaPoshta\Models\PlaceMap;
use Torgash\NovaPoshta\Models\Warehouse;
use Torgash\NovaPoshta\ServiceType;
use Torgash\NovaPoshta\Shipping\NovaPoshtaProvider;
use Torgash\NovaPoshta\Sync\WarehouseSync;
use Torgash\NovaPoshta\WarehouseType;
use Torgash\Shipping\Shipment;

/**
 * Choosing the branch a parcel goes to.
 *
 * The branches of one town are fetched when a customer first names it and
 * cached on a TTL — or in bulk, town by town, by a job that paces itself. The
 * picker itself is filled from that cache, so typing in it costs nothing, and a
 * carrier that is down leaves the last known branches in place rather than
 * emptying the last step of checkout.
 */
class BranchPickerTest extends TestCase
{
    protected string $settlementRef = 'e717110a-4b33-11e4-ab6d-005056801329';

    protected function setUp(): void
    {
        parent::setUp();

        app(SettingsManager::class)->set('torgash.novaposhta', 'api_key', 'test-key');
    }

    public function test_branches_are_fetched_once_and_then_read_from_the_cache(): void
    {
        Http::fake(['*' => Http::response($this->warehousesResponse())]);

        $first = app(WarehouseSync::class)->for($this->settlementRef);
        $second = app(WarehouseSync::class)->for($this->settlementRef);

        $this->assertCount(2, $first);
        $this->assertCount(2, $second);
        // One page fetched, and the second call did not go near the network.
        Http::assertSentCount(1);
    }

    public function test_a_branch_carries_what_the_picker_shows_and_what_it_can_take(): void
    {
        Http::fake(['*' => Http::response($this->warehousesResponse())]);

        $branch = app(WarehouseSync::class)->for($this->settlementRef)->firstWhere('number', '1');

        $this->assertSame('Відділення №1: вул. Повітряна, 2', $branch->name_uk);
        $this->assertSame('Дніпро, Повітряна, 2', $branch->short_uk);
        // Dnipro's branch №1 really is a cargo department in the live data —
        // still a branch as far as the picker is concerned, just not a locker.
        $this->assertSame('cargo', $branch->category);
        $this->assertSame(1100, $branch->max_weight_kg);
    }

    public function test_a_fulfilment_point_is_stored_but_never_offered_for_pickup(): void
    {
        Http::fake(['*' => Http::response($this->warehousesResponse(withFulfillment: true))]);

        $all = app(WarehouseSync::class)->for($this->settlementRef);
        // Kept in the table — the ops screen counts it, and the carrier's own
        // category is what tells it apart from a plain post office.
        $this->assertCount(3, $all);
        $this->assertSame('Fulfillment', $all->firstWhere('number', '7')->np_category);

        $offered = Warehouse::offered($this->settlementRef, ['branch', 'cargo', 'locker'])->pluck('number');

        $this->assertEqualsCanonicalizing(['1', '5'], $offered->all(), 'a customer cannot collect from a fulfilment warehouse');
    }

    public function test_a_carrier_that_is_down_keeps_the_branches_it_had(): void
    {
        Http::fake(['*' => Http::response($this->warehousesResponse())]);
        app(WarehouseSync::class)->for($this->settlementRef);

        // The TTL expires and the carrier answers with a failure. Nothing may
        // be dropped for that: day old branches are a far better checkout than
        // none, and the refusal must not be retried on the next keystroke
        // either — that is how a brief "To many requests" becomes a permanent
        // one. (Measured on the live API.)
        $this->expire();
        Http::fake(['*' => Http::response('', 500)]);

        (new RefreshTownBranches($this->settlementRef))->handle(app(WarehouseSync::class));

        $this->assertCount(2, app(WarehouseSync::class)->for($this->settlementRef));

        Http::fake(['*' => Http::response($this->warehousesResponse())]);
        app(WarehouseSync::class)->for($this->settlementRef);
        Http::assertNothingSent();
    }

    public function test_a_stale_town_is_served_from_the_table_and_refreshed_behind_the_customer(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->warehousesResponse())]);
        app(WarehouseSync::class)->for($this->settlementRef);

        $this->expire();
        // Re-faking also clears what has been recorded, so the assertion below
        // is about this request and not the one that filled the table.
        Http::fake(['*' => Http::response($this->warehousesResponse())]);

        // Kyiv is sixteen paced pages. Making the first shopper of the day wait
        // that out — to learn what the next one gets for free — is the wrong
        // trade once the town is already in the table.
        $branches = app(WarehouseSync::class)->for($this->settlementRef);

        $this->assertCount(2, $branches);
        Http::assertNothingSent();
        Queue::assertPushed(RefreshTownBranches::class);

        // And the keystroke after it does not queue a second one.
        app(WarehouseSync::class)->for($this->settlementRef);
        Queue::assertPushed(RefreshTownBranches::class, 1);
    }

    public function test_a_town_with_no_branches_at_all_is_worth_the_wait(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response($this->warehousesResponse())]);

        // Nothing to serve, so there is nothing to be quick about: fetch it in
        // the request rather than answering with an empty picker.
        $this->assertCount(2, app(WarehouseSync::class)->for($this->settlementRef));
        Queue::assertNothingPushed();
    }

    public function test_the_offer_carries_a_picker_filled_with_that_towns_branches(): void
    {
        Http::fake(['*' => Http::response($this->warehousesResponse())]);
        $place = $this->mappedPlace();

        $offers = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $place->id),
            [Shipment::single(new Package(subtotal: 100_00))],
            ['service_type' => ServiceType::BRANCH, 'calculate' => false],
        );

        $this->assertCount(1, $offers);
        $this->assertSame('branch', $offers[0]->key);
        $this->assertSame('torgash.novaposhta::checkout.warehouses', $offers[0]->fieldsView);
        // Nothing ticked means every kind of pickup point, locker included —
        // which kind is a tick on the method, not a variant of it.
        $this->assertSame(
            ['Відділення №1: вул. Повітряна, 2', 'Поштомат №5: вул. Січова, 1'],
            $offers[0]->fieldsData['warehouses']->pluck('name_uk')->all(),
        );
    }

    public function test_a_method_ticked_down_to_lockers_offers_lockers_and_nothing_else(): void
    {
        Http::fake(['*' => Http::response($this->warehousesResponse())]);
        $place = $this->mappedPlace();

        $offers = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $place->id),
            [Shipment::single(new Package(subtotal: 100_00))],
            $this->lockersOnly(),
        );

        $this->assertSame(['Поштомат №5: вул. Січова, 1'], $offers[0]->fieldsData['warehouses']->pluck('name_uk')->all());
    }

    public function test_a_method_saved_as_the_old_locker_variant_still_offers_lockers(): void
    {
        // "To a parcel locker" used to be a third variant. A method saved then
        // must keep doing exactly what it did — read as "to a branch, lockers
        // only" — rather than quietly start offering every branch in town.
        Http::fake(['*' => Http::response($this->warehousesResponse())]);
        $place = $this->mappedPlace();

        $offers = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $place->id),
            [Shipment::single(new Package(subtotal: 100_00))],
            ['service_type' => ServiceType::LEGACY_LOCKER, 'calculate' => false],
        );

        $this->assertSame(['Поштомат №5: вул. Січова, 1'], $offers[0]->fieldsData['warehouses']->pluck('name_uk')->all());
    }

    public function test_a_parcel_too_heavy_for_the_offered_points_does_not_list_them(): void
    {
        Http::fake(['*' => Http::response($this->warehousesResponse())]);
        $place = $this->mappedPlace();

        // The branch takes 1100 kg, the locker 20; a 30 kg parcel fits one.
        $offers = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $place->id),
            [Shipment::single(new Package(subtotal: 100_00, weight: 30_000))],
            $this->lockersOnly(),
        );

        // Nothing can take it, so the variant is not offered at all rather than
        // offered with a point that will refuse the parcel at the counter.
        $this->assertSame([], $offers);
    }

    /** A "to a branch" method narrowed to lockers — what used to be a variant. */
    protected function lockersOnly(): array
    {
        return [
            'service_type' => ServiceType::BRANCH,
            'warehouse_types' => [WarehouseType::PARCEL_LOCKER],
            'calculate' => false,
        ];
    }

    /**
     * Why the list is short, said out loud.
     *
     * Every parcel locker the carrier has states a 20 kg limit, so a 21 kg
     * basket takes a town's three hundred lockers off the list at once and
     * what is left reads as a shop with one branch in the city. The owner met
     * exactly that and had to ask; the picker now says how many points cannot
     * take this parcel.
     */
    public function test_the_picker_says_how_many_points_the_weight_hides(): void
    {
        Http::fake(['*' => Http::response($this->warehousesResponse())]);
        app(WarehouseSync::class)->for($this->settlementRef);

        $all = Warehouse::offered($this->settlementRef, ['branch', 'cargo', 'locker'])->count();
        // 21 kg: the cargo department takes it, the locker does not.
        $heavy = Warehouse::offered($this->settlementRef, ['branch', 'cargo', 'locker'], [], 21_000)->count();

        $this->assertSame(2, $all);
        $this->assertSame(1, $heavy);

        $html = view('torgash.novaposhta::checkout.warehouses', [
            'settlement' => $this->settlementRef,
            'types' => [],
            'weight' => 21_000,
            'total' => $heavy,
            'hidden_by_weight' => $all - $heavy,
            'parcel_kg' => 21,
            'chosen' => null,
            'warehouses' => Warehouse::offered($this->settlementRef, ['branch', 'cargo', 'locker'], [], 21_000)->get(),
        ])->render();

        $this->assertStringContainsString('21', $html);
        $this->assertStringContainsString('cannot', $html);
    }
    /**
     * A town named is a town fetched — in the background, before the delivery
     * step asks for it.
     *
     * The case this exists for: the carrier refuses the very first request for
     * an unknown town (a live "To many requests" is easy to earn), and the
     * customer who happened to name it gets no Nova Poshta at all, because the
     * fetch used to happen inside the request that needed the list. Warming it
     * while they are still typing their name makes that a rarity instead of a
     * coin toss.
     */
    public function test_naming_a_town_fetches_its_branches_in_the_background(): void
    {
        Queue::fake();
        $place = $this->mappedPlace()->id;

        hook_action('storefront.place_chosen', ['place_id' => $place, 'source' => 'test']);

        Queue::assertPushed(RefreshTownBranches::class);

        // …and naming it again does not queue a second one: a quote re-runs on
        // every keystroke in the rest of the form.
        hook_action('storefront.place_chosen', ['place_id' => $place, 'source' => 'test']);
        Queue::assertPushed(RefreshTownBranches::class, 1);
    }

    /** A shop that would rather not: the setting is the whole of it. */
    public function test_the_shop_can_switch_the_warming_off(): void
    {
        Queue::fake();
        app(SettingsManager::class)->set('torgash.novaposhta', 'prefetch_on_town', false);

        hook_action('storefront.place_chosen', ['place_id' => $this->mappedPlace()->id, 'source' => 'test']);

        Queue::assertNothingPushed();
    }

    /** A town the carrier does not serve is not a town to fetch. */
    public function test_a_town_with_no_settlement_of_its_own_is_not_fetched(): void
    {
        Queue::fake();
        $this->mappedPlace()->id;

        hook_action('storefront.place_chosen', ['place_id' => 999999, 'source' => 'test']);

        Queue::assertNothingPushed();
    }
    public function test_a_closed_branch_is_not_offered(): void
    {
        // Branches close, and the carrier says so with a status and a date
        // rather than dropping the row — so this is a fact to read, not a gap
        // to discover when a customer's parcel has nowhere to go.
        Http::fake(['*' => Http::response($this->warehousesResponse(status: 'Closed'))]);
        $place = $this->mappedPlace();

        $offers = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $place->id),
            [Shipment::single(new Package(subtotal: 100_00))],
            ['service_type' => ServiceType::BRANCH, 'calculate' => false],
        );

        $this->assertSame([], $offers);
    }

    public function test_the_weight_limit_is_read_from_whichever_column_carries_it(): void
    {
        // A real Kyiv branch has PlaceMaxWeightAllowed = 0 and
        // TotalMaxWeightAllowed = 200: reading only the first would offer it a
        // half-tonne parcel.
        Http::fake(['*' => Http::response($this->warehousesResponse(placeWeight: 0, totalWeight: 200))]);
        $place = $this->mappedPlace();

        $heavy = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $place->id),
            [Shipment::single(new Package(subtotal: 100_00, weight: 500_000))],
            ['service_type' => ServiceType::BRANCH, 'calculate' => false],
        );
        $light = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $place->id),
            [Shipment::single(new Package(subtotal: 100_00, weight: 10_000))],
            ['service_type' => ServiceType::BRANCH, 'calculate' => false],
        );

        $this->assertSame([], $heavy);
        $this->assertCount(1, $light);
    }

    public function test_a_branch_carries_todays_hours(): void
    {
        Http::fake(['*' => Http::response($this->warehousesResponse())]);

        $branch = app(WarehouseSync::class)->for($this->settlementRef)->firstWhere('number', '1');

        // Which of two branches on the same street is open now is what a
        // customer actually chooses on, so the schedule is stored, not skipped.
        // Read for the day the test runs on, not for a day the fixture happens
        // to like — the branch keeps shorter weekend hours.
        $this->assertSame(now()->isWeekend() ? '09:00-19:00' : '08:00-21:00', $branch->hoursToday());
    }

    public function test_the_week_is_folded_into_ranges_and_the_weight_limit_is_the_binding_one(): void
    {
        Http::fake(['*' => Http::response($this->warehousesResponse(placeWeight: 30, totalWeight: 200))]);

        $branch = app(WarehouseSync::class)->for($this->settlementRef)->firstWhere('number', '1');

        // Seven identical lines is not a schedule anybody reads: consecutive
        // days sharing a time collapse, the way every Ukrainian checkout (and
        // the carrier's own module) shows it.
        $this->assertSame(['Mo–Fr' => '08:00-21:00', 'Sa–Su' => '09:00-19:00'], $branch->openingHours());

        // One limit caps a single item and the other the whole shipment, so
        // what the branch will actually take is the lower of the two — even
        // when the carrier's own name text quotes the other one.
        $this->assertSame(30, $branch->weightLimitKg());
    }

    public function test_a_closed_day_is_not_printed_as_a_dash(): void
    {
        // Kyiv's private in-entrance lockers come back with "-" for every day.
        // Rendered literally that was "· today -" under every one of them, which
        // reads as a broken template rather than as "no hours".
        Http::fake(['*' => Http::response($this->warehousesResponse(schedule: '-'))]);

        $branch = app(WarehouseSync::class)->for($this->settlementRef)->firstWhere('number', '1');

        $this->assertNull($branch->hoursToday());
        // The week, though, still says which days are closed — a schedule with
        // days missing looks like a bug, "Mo–Su —" does not.
        $this->assertSame(['Mo–Su' => '—'], $branch->openingHours());
    }

    public function test_the_live_price_is_asked_for_only_when_a_sender_town_is_set(): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->warehousesResponse())
                ->push(['success' => true, 'data' => [['Cost' => 205, 'CostRedelivery' => 0]]]),
        ]);
        $place = $this->mappedPlace();

        // No sender configured: the carrier is not asked, so there is no live
        // price — cost is null, not 0. A `price_mode = bands` method prices it
        // by the shop's own table; a `price_mode = carrier` one treats the null
        // as carrier_unavailable rather than showing "Free" (PLAN §13.1).
        $offers = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $place->id),
            [Shipment::single(new Package(subtotal: 100_00))],
            ['service_type' => ServiceType::BRANCH, 'calculate' => true],
        );
        $this->assertNotEmpty($offers, 'the branch variant should be offered');
        $this->assertNull($offers[0]->cost);

        // With a sender set, the second quote asks for a price — and only for a
        // price: the branches of this town are already cached, which is the
        // whole point of caching them.
        app(SettingsManager::class)->set('torgash.novaposhta', 'sender_city_ref', 'sender-city-ref');

        $offers = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $place->id),
            [Shipment::single(new Package(subtotal: 100_00))],
            ['service_type' => ServiceType::BRANCH, 'calculate' => true],
        );

        // Hryvnia, and said so — the shipping module converts it.
        $this->assertSame(205, $offers[0]->cost);
        $this->assertSame('UAH', $offers[0]->currency);
    }

    public function test_the_picker_carries_one_page_and_the_server_answers_typing(): void
    {
        Http::fake(['*' => Http::response($this->warehousesResponse())]);
        $place = $this->mappedPlace();

        $offer = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $place->id),
            [Shipment::single(new Package(subtotal: 100_00))],
            ['service_type' => ServiceType::BRANCH, 'calculate' => false],
        )[0];

        // Shipping a whole city's branches into the page would be a few hundred
        // kilobytes in Kyiv, and a quote runs on every keystroke in the town
        // field — so the page carries a pageful and the server answers typing.
        $this->assertSame(2, $offer->fieldsData['total']);
        $this->assertLessThanOrEqual(BranchesController::PER_PAGE, $offer->fieldsData['warehouses']->count());

        $url = 'http://localhost/np/branches?settlement='.$this->settlementRef;

        $this->get($url.'&'.BranchesController::QUERY.'=Повітряна')
            ->assertOk()
            ->assertSee('Повітряна');

        // A search may not widen what the quote offered. This method is ticked
        // down to post offices and cargo departments, so no amount of typing
        // "postomat" into its picker produces one.
        $this->get($url.'&types='.WarehouseType::POST_OFFICE.','.WarehouseType::CARGO_DEPARTMENT
            .'&'.BranchesController::QUERY.'=Поштомат')
            ->assertOk()
            ->assertDontSee('Поштомат №5');
    }

    public function test_a_chosen_branch_survives_the_quote_running_again(): void
    {
        Http::fake(['*' => Http::response($this->warehousesResponse())]);
        $place = $this->mappedPlace();
        $ref = '0d545f59-e1c2-11e3-8c4a-0050568002cf';

        // Changing the postal code (or the method, or the town) re-quotes and
        // replaces this whole block. A choice that lived only in the browser
        // would un-select itself a second after the customer made it — which is
        // exactly what it did.
        $markup = $this->pickerFor($place, ['shipping_fields' => ['warehouse_ref' => $ref]]);

        $this->assertStringContainsString('value="'.$ref.'"', $markup);
        $this->assertStringContainsString('Повітряна', $markup);

        // But not a branch from wherever the customer used to be shipping: the
        // same field comes back after the town changes too.
        $stale = $this->pickerFor($place, ['shipping_fields' => ['warehouse_ref' => 'someone-elses-branch']]);
        $this->assertStringContainsString("chosen: ''", $stale);
    }

    /** The picker as the browser gets it, for a request carrying $input. */
    protected function pickerFor(Place $place, array $input): string
    {
        request()->merge($input);

        $offer = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $place->id),
            [Shipment::single(new Package(subtotal: 100_00))],
            ['service_type' => ServiceType::BRANCH, 'calculate' => false],
        )[0];

        return view($offer->fieldsView, $offer->fieldsData)->render();
    }

    public function test_the_search_box_is_named_what_the_endpoint_reads(): void
    {
        // The bug this exists for: HTMX sends the box's value under the box's
        // own name, so a name the endpoint does not read means typing quietly
        // does nothing — the unfiltered first page comes back and the picker
        // looks like it is working. Nothing that tested the endpoint directly
        // could see it, so this goes through the markup the browser gets.
        Http::fake(['*' => Http::response($this->warehousesResponse())]);
        $place = $this->mappedPlace();

        $offer = (new NovaPoshtaProvider)->quote(
            new Location(country: 'UA', placeId: $place->id),
            [Shipment::single(new Package(subtotal: 100_00))],
            ['service_type' => ServiceType::BRANCH, 'calculate' => false],
        )[0];

        $markup = view($offer->fieldsView, $offer->fieldsData)->render();

        preg_match('/<input name="([^"]+)"[^>]*hx-get="([^"]+)"/s', $markup, $box);
        $this->assertNotEmpty($box, 'the picker should carry a search box with an hx-get');

        [, $name, $endpoint] = $box;

        $endpoint = html_entity_decode($endpoint);

        $this->get($endpoint.'&'.$name.'=Повітряна')->assertOk()->assertSee('Повітряна');

        // The half that catches the bug: a search that matches nothing must
        // come back empty. Under the wrong name it came back full.
        $this->get($endpoint.'&'.$name.'=Хмельницького')
            ->assertOk()
            ->assertDontSee('Повітряна');
    }

    public function test_fetching_every_towns_branches_stops_when_the_carrier_stops_answering(): void
    {
        $this->mappedPlace();
        Http::fake(['*' => Http::response('', 500)]);

        (new ImpatientSyncWarehouses)->handle();

        // The town is retried — a refusal is a rolling budget that clears in
        // seconds, not a ban — but the run then ends rather than collecting
        // thousands more refusals. The next run continues where this stopped,
        // because freshness is per town.
        Http::assertSentCount(SyncWarehouses::RETRIES);
        $this->assertSame(0, Warehouse::count());
    }

    public function test_a_town_refused_once_is_asked_again_rather_than_abandoned(): void
    {
        $this->mappedPlace();

        // Refused, then answered — which is what the live API actually does:
        // measured, "To many requests" clears within five seconds.
        Http::fakeSequence()
            ->push('', 500)
            ->push($this->warehousesResponse(), 200);

        (new ImpatientSyncWarehouses)->handle();

        $this->assertSame(2, Warehouse::count());
    }

    /** Age this town's branches past the TTL. */
    protected function expire(): void
    {
        DB::table('m_np_sync')->where('key', 'like', 'warehouses:'.$this->settlementRef.'%')
            ->update(['synced_at' => now()->subDays(2)]);
    }

    protected function mappedPlace(): Place
    {
        $state = State::firstOrCreate(
            ['country' => 'UA', 'code' => 'BP'],
            ['name' => ['uk' => 'Дніпропетровська область'], 'enabled' => true],
        );

        $place = Place::create([
            'country' => 'UA', 'state_id' => $state->id,
            'name' => ['uk' => 'Дніпро'], 'type' => 'settlement', 'enabled' => true,
        ]);

        DB::table('m_np_settlements')->insertOrIgnore([
            'ref' => $this->settlementRef, 'kind' => 'settlement',
            'name_uk' => 'Дніпро', 'name_ru' => 'Днепр', 'area' => 'Дніпропетровська',
            'has_warehouse' => true, 'synced_at' => now(),
        ]);

        PlaceMap::create([
            'place_id' => $place->id,
            'settlement_ref' => $this->settlementRef,
            'city_ref' => 'db5c88f0-391c-11dd-90d9-001a92567626',
            'matched_by' => 'manual',
            'confidence' => 100,
            'checked_at' => now(),
        ]);

        return $place;
    }

    /**
     * The shape the live API answers with — one branch and one locker, with the
     * fields that decide whether either may be offered.
     */
    protected function warehousesResponse(
        string $status = 'Working',
        int $placeWeight = 1100,
        int $totalWeight = 0,
        ?string $schedule = null,
        bool $withFulfillment = false,
    ): array {
        // A real Dnipro branch's week: shorter at the weekend, which is what
        // makes folding the schedule into ranges worth doing at all. Pass a
        // single string to give every day the same value — "-" is what the
        // carrier sends for the private lockers that have no hours at all.
        $schedule = $schedule !== null
            ? array_fill_keys(
                ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'],
                $schedule,
            )
            : [
                'Monday' => '08:00-21:00', 'Tuesday' => '08:00-21:00', 'Wednesday' => '08:00-21:00',
                'Thursday' => '08:00-21:00', 'Friday' => '08:00-21:00',
                'Saturday' => '09:00-19:00', 'Sunday' => '09:00-19:00',
            ];

        return ['success' => true, 'data' => [
            [
                'Ref' => '0d545f59-e1c2-11e3-8c4a-0050568002cf',
                'Number' => '1',
                'Description' => 'Відділення №1: вул. Повітряна, 2',
                'DescriptionRu' => 'Отделение №1: ул. Повитряна, 2',
                'ShortAddress' => 'Дніпро, Повітряна, 2',
                'ShortAddressRu' => 'Днепр, Повитряна, 2',
                'TypeOfWarehouse' => \Torgash\NovaPoshta\WarehouseType::CARGO_DEPARTMENT,
                'Latitude' => '48.4698902675',
                'Longitude' => '35.0989405423',
                'PlaceMaxWeightAllowed' => (string) $placeWeight,
                'TotalMaxWeightAllowed' => (string) $totalWeight,
                'ReceivingLimitationsOnDimensions' => ['Width' => 220, 'Height' => 220, 'Length' => 600],
                'WarehouseStatus' => $status,
                'DenyToSelect' => '0',
                'CategoryOfWarehouse' => 'Branch',
                'Phone' => '380800500609',
                'PostalCodeUA' => '49022',
                'Reception' => $schedule,
                'Delivery' => $schedule,
            ],
            [
                'Ref' => '1d545f59-e1c2-11e3-8c4a-0050568002cf',
                'Number' => '5',
                'Description' => 'Поштомат №5: вул. Січова, 1',
                'DescriptionRu' => 'Почтомат №5: ул. Сечевая, 1',
                'ShortAddress' => 'Дніпро, Січова, 1',
                'ShortAddressRu' => 'Днепр, Сечевая, 1',
                'TypeOfWarehouse' => \Torgash\NovaPoshta\WarehouseType::PARCEL_LOCKER,
                'Latitude' => '48.47',
                'Longitude' => '35.10',
                'PlaceMaxWeightAllowed' => '20',
                'TotalMaxWeightAllowed' => '0',
                'ReceivingLimitationsOnDimensions' => ['Width' => 40, 'Height' => 40, 'Length' => 60],
                'WarehouseStatus' => $status,
                'DenyToSelect' => '0',
                'CategoryOfWarehouse' => 'Postomat',
                'Reception' => $schedule,
                'Delivery' => $schedule,
            ],
            ...($withFulfillment ? [[
                'Ref' => '2d545f59-e1c2-11e3-8c4a-0050568002cf',
                'Number' => '7',
                'Description' => 'Склад №7 (Фулфілмент Дніпро)',
                'DescriptionRu' => 'Склад №7 (Фулфилмент Днепр)',
                'ShortAddress' => 'Дніпро',
                'ShortAddressRu' => 'Днепр',
                'TypeOfWarehouse' => \Torgash\NovaPoshta\WarehouseType::POST_OFFICE,
                'Latitude' => '48.48',
                'Longitude' => '35.11',
                'PlaceMaxWeightAllowed' => '30',
                'TotalMaxWeightAllowed' => '0',
                'ReceivingLimitationsOnDimensions' => ['Width' => 100, 'Height' => 100, 'Length' => 100],
                'WarehouseStatus' => $status,
                'DenyToSelect' => '0',
                // A customer cannot collect a parcel from a fulfilment
                // warehouse — the type UUID makes it look like a plain branch.
                'CategoryOfWarehouse' => 'Fulfillment',
                'Reception' => $schedule,
                'Delivery' => $schedule,
            ]] : []),
        ]];
    }
}
