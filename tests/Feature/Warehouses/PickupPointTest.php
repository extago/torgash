<?php

namespace Tests\Feature\Warehouses;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Locations\Models\Place;
use Torgash\Warehouses\Hours;
use Torgash\Warehouses\Models\Warehouse;

/**
 * A warehouse that is also a place on the map: which town it is in, whether a
 * customer may collect an order there, and when it is open (PLAN §20.4/1).
 *
 * The step matters on its own, before any click & collect exists, because the
 * same `place_id` is the sender's address a carrier prices a parcel from — the
 * "origin" that has been a null in the shipping module since it was written.
 */
class PickupPointTest extends TestCase
{
    public function test_a_warehouse_records_its_town_pickup_flag_and_hours(): void
    {
        $warehouse = Warehouse::create(['name' => ['en' => 'Shop'], 'sort' => 5]);

        $this->actingAs($this->admin());

        $this->put("http://localhost/admin/warehouses/{$warehouse->id}", [
            'name' => 'Shop',
            'place_id' => '703448',
            'phone' => '+380 44 000 00 00',
            'is_pickup' => '1',
            'enabled' => '1',
            'hours' => [
                'mon' => ['from' => '09:00', 'to' => '18:00'],
                'tue' => ['from' => '9:00', 'to' => '18:00'],
                // Half a range is not a range — this day is closed, and saving
                // it as an open-ended one is how a customer turns up at eight.
                'wed' => ['from' => '09:00', 'to' => ''],
                'sat' => ['from' => '10:00', 'to' => '16:00'],
            ],
        ])->assertRedirect();

        $warehouse->refresh();

        $this->assertSame(703448, $warehouse->place_id);
        $this->assertTrue($warehouse->is_pickup);
        $this->assertSame('+380 44 000 00 00', $warehouse->phone);
        // assertEquals, not assertSame: jsonb does not keep key order (it comes
        // back mon, sat, tue), which is exactly why Hours walks the week itself
        // instead of trusting the order the days are stored in.
        $this->assertEquals([
            'mon' => ['09:00', '18:00'],
            'tue' => ['09:00', '18:00'],
            'sat' => ['10:00', '16:00'],
        ], $warehouse->hours);
    }

    /**
     * The pickup box implies the visibility box, and the form disables the
     * latter to say so — a disabled checkbox posts nothing, so the server has
     * to apply the same rule or every save would un-publish the point.
     */
    public function test_a_point_orders_can_be_collected_from_is_shown_to_customers(): void
    {
        $warehouse = Warehouse::create(['name' => ['en' => 'Shop']]);

        $this->actingAs($this->admin());

        $this->put("http://localhost/admin/warehouses/{$warehouse->id}", [
            'name' => 'Shop',
            'is_pickup' => '1',
            'enabled' => '1',
        ])->assertRedirect();

        $this->assertTrue($warehouse->refresh()->is_public);
        $this->assertTrue(Warehouse::pickup()->whereKey($warehouse->id)->exists());
    }

    /**
     * The screens render, and the list names the town rather than the id it
     * stores — the place registry answers `locations.place.names` for both.
     */
    public function test_the_admin_screens_show_the_town_by_name(): void
    {
        $place = Place::create([
            'country' => 'UA', 'name' => ['en' => 'Lviv'], 'type' => 'settlement', 'enabled' => true,
        ]);

        $warehouse = Warehouse::create([
            'name' => ['en' => 'Lviv shop'], 'place_id' => $place->id, 'is_pickup' => true, 'is_public' => true,
        ]);

        $this->actingAs($this->admin());

        $this->get('http://localhost/admin/warehouses')
            ->assertOk()
            ->assertSee('Lviv shop')
            ->assertSee('Lviv');

        $this->get("http://localhost/admin/warehouses/{$warehouse->id}/edit")
            ->assertOk()
            ->assertSee('Opening hours')
            ->assertSee('Orders can be collected here');
    }

    /** A disabled warehouse is not a place to send a customer to. */
    public function test_a_disabled_pickup_point_is_not_offered(): void
    {
        $warehouse = Warehouse::create([
            'name' => ['en' => 'Closed shop'],
            'is_public' => true, 'is_pickup' => true, 'enabled' => false,
        ]);

        $this->assertFalse(Warehouse::pickup()->whereKey($warehouse->id)->exists());
    }

    public function test_hours_read_out_as_grouped_lines(): void
    {
        $summary = Hours::summary([
            'mon' => ['09:00', '18:00'],
            'tue' => ['09:00', '18:00'],
            'wed' => ['09:00', '18:00'],
            'thu' => ['09:00', '18:00'],
            'fri' => ['09:00', '18:00'],
            'sat' => ['10:00', '16:00'],
        ]);

        $this->assertSame([
            ['days' => 'Mon–Fri', 'time' => '09:00–18:00'],
            ['days' => 'Sat', 'time' => '10:00–16:00'],
        ], $summary);
    }

    /** A closed day breaks the run: Mon and Wed are not "Mon–Wed". */
    public function test_a_closed_day_breaks_the_run(): void
    {
        $summary = Hours::summary([
            'mon' => ['09:00', '18:00'],
            'wed' => ['09:00', '18:00'],
        ]);

        $this->assertSame([
            ['days' => 'Mon', 'time' => '09:00–18:00'],
            ['days' => 'Wed', 'time' => '09:00–18:00'],
        ], $summary);
    }

    public function test_nothing_open_stores_no_hours_at_all(): void
    {
        $this->assertNull(Hours::fromInput(['mon' => ['from' => '', 'to' => '']]));
    }

    /**
     * The shop's own address, as a carrier sees it. The default warehouse
     * answers — unless it has no town, in which case a warehouse that HAS one
     * answers rather than the shop pricing parcels from nowhere.
     */
    public function test_the_origin_is_the_dispatching_warehouse(): void
    {
        Warehouse::query()->update(['is_default' => false, 'enabled' => false]);

        $main = Warehouse::create(['name' => ['en' => 'Main'], 'is_default' => true, 'sort' => 0]);
        $lviv = Warehouse::create(['name' => ['en' => 'Lviv'], 'place_id' => 702550, 'sort' => 10]);

        // The default warehouse was never placed on the map, so it is not the
        // answer: the only warehouse that knows where it is, is.
        $this->assertSame(['place_id' => 702550], hook_filter('shipping.origin', null, []));

        $main->update(['place_id' => 703448]);

        $this->assertSame(['place_id' => 703448], hook_filter('shipping.origin', null, []));

        $lviv->update(['enabled' => false]);
        $main->update(['place_id' => null]);

        // Nobody left who knows: null, and the carrier module falls back to its
        // own sender setting rather than being handed a wrong town.
        $this->assertNull(hook_filter('shipping.origin', null, []));
    }

    protected function admin(): User
    {
        $user = User::firstWhere('email', 'pickup@test');

        if (! $user) {
            $user = User::create(['name' => 'Admin', 'email' => 'pickup@test', 'password' => bcrypt('x')]);
            $user->assignRole(Role::findOrCreate('admin'));
        }

        return $user;
    }
}
