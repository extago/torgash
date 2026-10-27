<?php

namespace Tests\Feature\Automations;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;

/**
 * Actions that change the order itself.
 *
 * Two things are worth a test and the rest is plumbing: an automation must move
 * a status through exactly the code the admin button uses (or a shop's stock
 * starts depending on which screen the change came from), and a rule's own
 * change must not trigger rules — otherwise "when the status changes, set the
 * status" runs until somebody notices.
 */
class InternalActionsTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();

        $this->product = Product::create([
            'sku' => 'INT-1',
            'name' => ['en' => 'Internal widget'],
            'slug' => 'internal-widget',
            'price' => 50_00,
            'stock' => 10,
            'status' => 'active',
        ]);
    }

    public function test_setting_a_status_takes_the_stock_exactly_like_the_admin_button(): void
    {
        $deducting = OrderStatus::where('inventory_action', 'decrease')->firstOrFail();

        $rule = $this->rule('set_status', ['status' => $deducting->code]);
        $order = $this->order();

        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['payment_state'], 'k1'))->handle();

        $order->refresh();

        $this->assertSame($deducting->code, $order->status);
        $this->assertTrue($order->stock_deducted);
        $this->assertSame(8, $this->product->fresh()->stock, 'two came out of stock, once');
        // The order's own history says which rule did it: a manager who finds a
        // status they did not set must be able to see why.
        $this->assertTrue($order->logs()->get()->contains(fn ($log) => str_starts_with($log->text(), 'Ship it')));
    }

    public function test_a_status_that_is_already_set_is_skipped_not_repeated(): void
    {
        $already = OrderStatus::orderBy('position')->firstOrFail()->code;

        $rule = $this->rule('set_status', ['status' => $already]);
        $order = $this->order(['status' => $already]);

        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['total'], 'k2'))->handle();

        $this->assertSame(RuleRun::SKIPPED, RuleRun::latest('id')->firstOrFail()->status);
        $this->assertSame(10, $this->product->fresh()->stock, 'a no-op must not move stock either');
    }

    public function test_a_status_this_shop_does_not_have_fails_loudly(): void
    {
        // The case an imported template lands in: the code travelled, the status
        // did not. Silence here would be a rule that quietly does nothing.
        $rule = $this->rule('set_status', ['status' => 'shipped_by_owl']);
        $order = $this->order();

        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['total'], 'k6'))->handle();

        $run = RuleRun::latest('id')->firstOrFail();

        $this->assertSame(RuleRun::FAILED, $run->status);
        $this->assertStringContainsString('shipped_by_owl', $run->summary);
    }

    public function test_a_rules_own_change_does_not_trigger_rules(): void
    {
        $other = OrderStatus::where('code', '!=', 'new')->value('code');

        // The loop: a rule that fires on a status change and changes the status.
        $rule = $this->rule('set_status', ['status' => $other], ['fields' => ['status']]);
        $order = $this->order();

        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['status'], 'k3'))->handle();

        $this->assertSame($other, $order->fresh()->status);
        // One run, not a chain: the guard is up while the action runs, and the
        // change it makes is announced inside that window.
        $this->assertSame(1, RuleRun::count());
    }

    public function test_a_manager_can_be_assigned_but_only_a_staff_one(): void
    {
        $role = Role::findOrCreate('sales');
        $role->syncPermissions(['admin.access', 'orders.manage']);
        $staff = User::create(['name' => 'Sales Sam', 'email' => 'sam@torgash.local', 'password' => 'secret123']);
        $staff->assignRole($role);

        $customer = User::create(['name' => 'Buyer', 'email' => 'buyer2@example.test', 'password' => 'secret123']);

        $order = $this->order();

        (new RunRule($this->rule('set_manager', ['manager_id' => $staff->id])->id, $order->id, 'checkout.order.changed', ['total'], 'k4'))->handle();
        $this->assertSame($staff->id, $order->fresh()->manager_id);

        (new RunRule($this->rule('set_manager', ['manager_id' => $customer->id])->id, $order->id, 'checkout.order.changed', ['total'], 'k5'))->handle();

        $this->assertSame($staff->id, $order->fresh()->manager_id, 'a customer must not end up holding an order');
        $this->assertSame(RuleRun::FAILED, RuleRun::latest('id')->firstOrFail()->status);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $overrides
     */
    protected function rule(string $type, array $config, array $overrides = []): Rule
    {
        $rule = Rule::create(array_merge([
            'name' => 'Ship it',
            'event' => 'checkout.order.changed',
            'enabled' => true,
            'delay_minutes' => 0,
        ], $overrides));

        $rule->actions()->create(['type' => $type, 'config' => $config, 'position' => 0]);

        return $rule->fresh('actions');
    }

    /** @param  array<string, mixed>  $overrides */
    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TG-INT'.random_int(1000, 9999),
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
                'name' => 'Internal widget',
                'qty' => 2,
                'unit_price' => 50_00,
                'line_total' => 100_00,
            ]],
        ], $overrides))->fresh();
    }
}
