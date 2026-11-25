<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Tests\TestCase;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;

/**
 * Orders placed without signing in reach the account they belong to: by
 * themselves once the account CONFIRMS the contact, or by a manager's hand
 * from the customer's card (GuestOrders).
 */
class GuestOrdersTest extends TestCase
{
    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'currency_code' => 'USD',
            'subtotal' => 100_00,
            'shipping_cost' => 0,
            'total' => 100_00,
            'status' => 'new',
            'stock_deducted' => false,
            'items' => [],
        ], $overrides));
    }

    public function test_a_confirmed_email_takes_the_guest_orders_placed_with_it(): void
    {
        $guest = $this->order(['email' => 'Buyer@Example.test']);
        $stranger = $this->order(['email' => 'someone@example.test']);

        $user = User::factory()->create(['email' => 'buyer@example.test', 'email_verified_at' => now()]);

        $this->assertSame($user->id, $guest->fresh()->user_id);
        $this->assertNull($stranger->fresh()->user_id);
        $this->assertTrue(OrderLog::where('order_id', $guest->id)->where('description', 'like', 'Attached to the account%')->exists());
    }

    public function test_an_unconfirmed_email_takes_nothing_until_it_is_confirmed(): void
    {
        $guest = $this->order();
        $user = User::factory()->unverified()->create(['email' => 'buyer@example.test']);

        $this->assertNull($guest->fresh()->user_id, 'anyone could register with a stranger\'s address');

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->assertSame($user->id, $guest->fresh()->user_id);
    }

    public function test_a_confirmed_phone_matches_however_it_was_typed(): void
    {
        $guest = $this->order(['email' => null, 'phone' => '+380 (67) 123-45-67']);
        $user = User::factory()->unverified()->create(['email' => 'other@example.test']);

        $user->forceFill(['phone' => '+380671234567', 'phone_verified_at' => now()])->save();

        $this->assertSame($user->id, $guest->fresh()->user_id);
    }

    public function test_an_order_with_an_owner_and_a_staff_account_are_left_alone(): void
    {
        $owner = User::factory()->create(['email' => 'first@example.test']);
        $owned = $this->order(['user_id' => $owner->id, 'email' => 'buyer@example.test']);
        $guest = $this->order(['email' => 'manager@example.test']);

        User::factory()->create(['email' => 'buyer@example.test', 'email_verified_at' => now()]);
        $manager = User::factory()->unverified()->create(['email' => 'manager@example.test']);
        $manager->assignRole('admin');
        $manager->forceFill(['email_verified_at' => now()])->save();

        $this->assertSame($owner->id, $owned->fresh()->user_id);
        $this->assertNull($guest->fresh()->user_id);
    }

    public function test_the_manager_attaches_them_from_the_customer_card(): void
    {
        $admin = User::role('admin')->firstOrFail();
        $customer = User::factory()->unverified()->create(['email' => 'buyer@example.test']);
        $mine = $this->order(['order_number' => 'TG-GUEST1']);
        $notMine = $this->order(['email' => 'someone@example.test', 'order_number' => 'TG-GUEST2']);

        $this->actingAs($admin)->get(route('admin.customers.edit', $customer))->assertOk()
            ->assertSee('TG-GUEST1')->assertDontSee('TG-GUEST2')
            ->assertSee(route('admin.orders.attach', $customer->id), false);

        // An id the card did not offer attaches nothing.
        $this->actingAs($admin)->post(route('admin.orders.attach', $customer->id), ['orders' => [$mine->id, $notMine->id]])
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame($customer->id, $mine->fresh()->user_id);
        $this->assertNull($notMine->fresh()->user_id);
        $this->assertSame($admin->id, OrderLog::where('order_id', $mine->id)->where('description', 'Attached to the account :account')->value('user_id'));

        $this->actingAs($admin)->get(route('admin.customers.edit', $customer))->assertOk()
            ->assertDontSee(route('admin.orders.attach', $customer->id), false);
    }
}
