<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Shipping\Models\ShippingMethod;
use Torgash\Shipping\Models\ShippingRate;
use Torgash\Shipping\Models\Zone;

/**
 * Rates are edited inside the method screen, one row at a time.
 *
 * Pinned here because the inline editor has two things a full page did not:
 * the pair (method, zone) IS the identity — one endpoint both creates and
 * updates — and every answer is a single table row, so a response that carries
 * a whole admin layout (or a redirect) would swap the page into a cell.
 */
class ShippingRateInlineTest extends TestCase
{
    protected ShippingMethod $method;

    protected Zone $zone;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs($this->admin());

        $this->method = ShippingMethod::create([
            'name' => ['en' => 'Courier'], 'enabled' => true, 'position' => 0,
        ]);
        $this->zone = Zone::create(['name' => ['en' => 'Test zone'], 'enabled' => true, 'position' => 0]);
    }

    public function test_the_method_screen_lists_every_zone_priced_or_not(): void
    {
        $priced = Zone::create(['name' => ['en' => 'Priced zone'], 'enabled' => true, 'position' => 1]);
        $this->method->rates()->create(['zone_id' => $priced->id, 'base_rate' => 500, 'enabled' => true]);

        $this->get($this->url('/edit'))
            ->assertOk()
            ->assertSee('Zones and price')
            ->assertSee('Priced zone')
            // The unpriced zone is listed too: "where is this NOT offered" is
            // half the answer the tab exists to give.
            ->assertSee('Test zone')
            ->assertSee('Not offered here.');
    }

    public function test_saving_a_rate_for_a_zone_that_has_none_creates_it(): void
    {
        $this->htmx()->put($this->rateUrl(), [
            'base_rate' => '12.50',
            'free_over' => '',
            'enabled' => '1',
            'rows' => [
                ['axis' => 'W', 'range_from' => '1', 'range_to' => '5', 'value' => '2', 'value_type' => 'fixed', 'per_unit' => '1'],
            ],
        ])->assertOk()->assertSee('rate-zone-'.$this->zone->id, false);

        $rate = $this->rate();

        $this->assertSame(1250, $rate->base_rate);
        $this->assertNull($rate->free_over);

        $row = $rate->rows->sole();
        // Kilograms in the form, grams in the table; money in minor units.
        $this->assertSame([1000, 5000, 200, true], [$row->range_from, $row->range_to, $row->value_amount, $row->per_unit]);
    }

    public function test_saving_again_updates_the_same_rate_and_replaces_its_bands(): void
    {
        $this->htmx()->put($this->rateUrl(), [
            'base_rate' => '10', 'enabled' => '1',
            'rows' => [['axis' => 'W', 'range_from' => '0', 'range_to' => '', 'value' => '1', 'value_type' => 'fixed']],
        ])->assertOk();

        $this->htmx()->put($this->rateUrl(), [
            'base_rate' => '20', 'free_over' => '100', 'enabled' => '1',
            'rows' => [['axis' => 'C', 'range_from' => '0', 'range_to' => '', 'value' => '5', 'value_type' => 'percent']],
        ])->assertOk();

        // One rate for the pair, not two: the second save is an update.
        $this->assertSame(1, ShippingRate::where('shipping_method_id', $this->method->id)
            ->where('zone_id', $this->zone->id)->count());

        $rate = $this->rate();
        $this->assertSame([2000, 10000], [$rate->base_rate, $rate->free_over]);
        $this->assertSame(['C', 'percent', '5'], [
            $rate->rows->sole()->axis,
            $rate->rows->sole()->value_type,
            rtrim(rtrim((string) $rate->rows->sole()->value_percent, '0'), '.'),
        ]);
    }

    public function test_an_answer_is_one_table_row_and_not_a_page(): void
    {
        $response = $this->htmx()->get($this->rateUrl().'/edit')->assertOk();

        $this->assertStringStartsWith('<tr', trim($response->getContent()));
        // A layout swapped into a cell is the failure this guards against.
        $response->assertDontSee('<!DOCTYPE', false)->assertSee('Save rate');
    }

    public function test_removing_a_rate_takes_the_row_back_to_not_offered(): void
    {
        $this->method->rates()->create(['zone_id' => $this->zone->id, 'base_rate' => 500, 'enabled' => true]);

        $this->htmx()->delete($this->rateUrl())
            ->assertOk()
            ->assertSee('Not offered here.')
            ->assertSee('Set a rate');

        $this->assertNull($this->rate());
    }

    public function test_a_bad_amount_answers_with_the_editor_and_saves_nothing(): void
    {
        $this->htmx()->put($this->rateUrl(), ['base_rate' => '-5', 'enabled' => '1'])
            ->assertOk()
            // The editor comes back refilled, because a swap has no redirect to
            // carry old input on.
            ->assertSee('Save rate')
            ->assertSee('-5', false);

        $this->assertNull($this->rate());
    }

    public function test_following_a_fragment_url_by_hand_lands_on_the_method_screen(): void
    {
        $this->get($this->rateUrl().'/edit')
            ->assertRedirect($this->url('/edit').'?tab=rates');
    }

    protected function rate(): ?ShippingRate
    {
        return ShippingRate::with('rows')
            ->where('shipping_method_id', $this->method->id)
            ->where('zone_id', $this->zone->id)
            ->first();
    }

    protected function htmx(): static
    {
        return $this->withHeaders(['HX-Request' => 'true']);
    }

    protected function url(string $suffix = ''): string
    {
        return 'http://localhost/admin/shipping/methods/'.$this->method->id.$suffix;
    }

    protected function rateUrl(): string
    {
        return $this->url('/rates/'.$this->zone->id);
    }

    protected function admin(): User
    {
        $user = User::firstWhere('email', 'rate-inline@test');

        if (! $user) {
            $user = User::create(['name' => 'Admin', 'email' => 'rate-inline@test', 'password' => bcrypt('x')]);
            $user->assignRole(Role::findOrCreate('admin'));
        }

        return $user;
    }
}
