<?php

namespace Tests\Feature\Checkout;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * `checkout.order.changed` — the one announcement that an order is not what it
 * was, with before and after.
 *
 * The point of these tests is not that a hook fires; it is the SHAPE of what
 * fires. An automation rule, an API consumer or a CRM push reacts to this, so
 * the guarantees worth nailing down are: one event per admin action however many
 * saves it took, a diff that spans the whole action rather than an intermediate
 * state, nothing at all for a placement, and coverage of edits made by a module
 * that has never heard of this hook.
 */
class OrderChangedTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    protected array $events = [];

    protected User $admin;

    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();

        $this->product = Product::create([
            'sku' => 'CHG-1',
            'name' => ['en' => 'Changeable widget'],
            'slug' => 'changeable-widget',
            'price' => 50_00,
            'stock' => 10,
            'status' => 'active',
        ]);

        hook_listen('checkout.order.changed', function (array $context) {
            $this->events[] = $context;
        });
    }

    public function test_placing_an_order_is_not_a_change(): void
    {
        Mail::fake();

        $this->post('http://localhost/cart/add/changeable-widget', ['qty' => 1])->assertOk();
        $this->post('http://localhost/checkout', [
            'customer_name' => 'Guest Buyer',
            'email' => 'guest@example.test',
            'address_line' => '1 Test Street',
            'city' => 'Testville',
            'shipping_method_id' => ShippingMethod::where('enabled', true)->value('id'),
            'payment_method_id' => PaymentMethod::where('enabled', true)->value('id'),
        ])->assertRedirect();

        // The checkout writes the order number and the lines onto the row it
        // just inserted; that is placement, announced by checkout.order_placed.
        $this->assertSame([], $this->events);
    }

    public function test_a_status_change_says_what_it_was_and_what_it_is_now(): void
    {
        Mail::fake();

        $order = $this->order();
        $to = OrderStatus::where('code', '!=', 'new')->value('code');

        $this->actingAs($this->admin)
            ->patch($this->url($order, 'status'), ['status' => $to])
            ->assertOk();

        $event = $this->onlyEvent();

        $this->assertSame($order->id, $event['order']->id);
        $this->assertSame('new', $event['before']['status']);
        $this->assertSame($to, $event['after']['status']);
        $this->assertContains('status', $event['changed']);
    }

    public function test_editing_the_contents_arrives_as_one_event_spanning_the_whole_edit(): void
    {
        $order = $this->order();

        $this->actingAs($this->admin)
            ->patch($this->url($order, 'items/'.$this->product->id), ['qty' => 3, 'unit_price' => '50.00'])
            ->assertOk();

        // OrderTotals saves twice on purpose (lines and subtotal first, so the
        // extra-total modules can re-snapshot, then the total). A consumer must
        // still see one event, and its "before" must be the money from before
        // the edit — not the half-updated row the second save started from.
        $event = $this->onlyEvent();

        $this->assertSame(100_00, $event['before']['subtotal']);
        $this->assertSame(150_00, $event['after']['subtotal']);
        $this->assertSame(100_00, $event['before']['total']);
        $this->assertSame(150_00, $event['after']['total']);
        $this->assertSame(2, $event['before']['items'][0]['qty']);
        $this->assertSame(3, $event['after']['items'][0]['qty']);
    }

    public function test_an_edit_made_by_another_module_is_announced_too(): void
    {
        $order = $this->order();
        $name = ShippingMethod::where('enabled', true)->firstOrFail()->t('name');

        // torgash.shipping owns this screen and never mentions the hook: the
        // funnel is the model, so a module cannot forget to announce.
        $this->actingAs($this->admin)
            ->patch('http://localhost/admin/shipping/orders/'.$order->id.'/method', [
                'shipping_method_name' => $name,
                'shipping_cost' => '7.50',
            ])
            ->assertRedirect();

        $event = $this->onlyEvent();

        $this->assertSame(0, $event['before']['shipping_cost']);
        $this->assertSame(7_50, $event['after']['shipping_cost']);
        $this->assertSame($name, $event['after']['shipping_method_name']);
        $this->assertSame(100_00 + 7_50, $event['after']['total']);
    }

    public function test_setting_a_field_to_what_it_already_was_is_not_a_change(): void
    {
        $manager = $this->staffMember();
        $order = $this->order(['manager_id' => $manager->id]);

        $this->actingAs($this->admin)
            ->patch($this->url($order, 'manager'), ['manager_id' => $manager->id])
            ->assertOk();

        $this->assertSame([], $this->events);
    }

    // ---------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $overrides */
    protected function order(array $overrides = []): Order
    {
        $order = Order::create(array_merge([
            'order_number' => 'TG-CHG'.random_int(1000, 9999),
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
            'items' => [[
                'product_id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => $this->product->t('name'),
                'qty' => 2,
                'unit_price' => 50_00,
                'line_total' => 100_00,
            ]],
        ], $overrides));

        // The instance that did the INSERT suppresses its own diffs, which is
        // right for the checkout and wrong for a fixture: the request under test
        // must meet this order the way an admin does, freshly loaded.
        return $order->fresh();
    }

    protected function staffMember(): User
    {
        $user = User::create(['name' => 'Sales Sam', 'email' => 'sam@torgash.local', 'password' => 'secret123']);
        $user->assignRole('admin');

        return $user;
    }

    /** @return array<string, mixed> */
    protected function onlyEvent(): array
    {
        $this->assertCount(1, $this->events, 'one admin action must announce exactly one change');

        return $this->events[0];
    }

    protected function url(Order $order, string $suffix = ''): string
    {
        return rtrim('http://localhost/admin/orders/'.$order->order_number.'/'.$suffix, '/');
    }
}
