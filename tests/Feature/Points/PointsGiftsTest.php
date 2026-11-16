<?php

namespace Tests\Feature\Points;

use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Models\PaymentMethod;
use Torgash\Checkout\Orders\OrderStatusChanger;
use Torgash\Points\Ledger;
use Torgash\Points\Models\Entry;
use Torgash\Points\Models\OrderPoints;
use Torgash\Promotions\Models\Promotion;
use Torgash\Shipping\Models\ShippingMethod;

/**
 * Points the shop gives beyond what goods earn (PLAN §74, stage 3): a cart
 * promotion's "Give bonus points" — the order's own, waiting and credited
 * with the rest — and an automation rule's action, given at once and once.
 */
class PointsGiftsTest extends TestCase
{
    protected User $customer;

    protected PaymentMethod $cash;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->customer = User::factory()->create();
        $this->cash = PaymentMethod::create(['name' => ['en' => 'Cash'], 'enabled' => true, 'position' => -1]);
        Product::create(['sku' => 'PG-1', 'name' => ['en' => 'Lamp'], 'slug' => 'pg-lamp', 'price' => 1000_00, 'stock' => 10, 'status' => 'active']);
    }

    public function test_a_promotion_gives_points_that_wait_and_are_credited_with_the_order(): void
    {
        $this->promotion(500);

        $order = $this->buy();
        $row = OrderPoints::findOrFail($order->id);

        $this->assertSame(10 + 500, $row->earn, 'what the goods earn, and the promotion\'s gift on top');
        $this->assertSame(0, app(Ledger::class)->balance($this->customer->id), 'waiting, like the rest');
        $this->assertTrue($order->logs()->get()->contains(fn ($log) => str_contains($log->text(), '500')));

        app(OrderStatusChanger::class)->change($order, OrderStatus::findOrFail('complete'));
        $this->assertSame(510, app(Ledger::class)->balance($this->customer->id));

        // …and taken back with it.
        app(OrderStatusChanger::class)->change($order->fresh(), OrderStatus::findOrFail('cancelled'));
        $this->assertSame(0, app(Ledger::class)->balance($this->customer->id));
    }

    public function test_rechecking_the_promotions_gives_once_and_a_promotion_gone_gives_nothing(): void
    {
        $promotion = $this->promotion(500);
        $order = $this->buy();
        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $this->actingAs($admin)->post('http://localhost/admin/promotions/orders/'.$order->order_number.'/reapply')->assertOk();
        $this->assertSame(510, OrderPoints::findOrFail($order->id)->earn);

        $promotion->update(['enabled' => false]);
        app(\Torgash\Promotions\PromotionCache::class)->flush();
        $this->actingAs($admin)->post('http://localhost/admin/promotions/orders/'.$order->order_number.'/reapply')->assertOk();
        $this->assertSame(10, OrderPoints::findOrFail($order->id)->earn);
    }

    public function test_the_promotion_form_offers_it_and_the_list_says_it_in_words(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $promotion = $this->promotion(500);

        $this->actingAs($admin)->get('http://localhost/admin/promotions/'.$promotion->id.'/edit')->assertOk()
            ->assertSee('value="give_points"', false);
        $this->actingAs($admin)->get('http://localhost/admin/promotions')->assertOk()->assertSee('+500');

        // …and a rule's form offers the action with its two fields.
        $this->actingAs($admin)->get('http://localhost/admin/automations/create')->assertOk()
            ->assertSee('give_points', false)
            ->assertSee(__('Give bonus points'));
    }

    public function test_a_rule_gives_points_at_once_and_once_per_event(): void
    {
        $rule = Rule::create(['name' => 'Thanks', 'event' => 'checkout.order.changed', 'enabled' => true, 'delay_minutes' => 0]);
        $rule->actions()->create(['type' => 'give_points', 'config' => ['points' => '150', 'reason' => 'Thank you for order {{ order.number }}'], 'position' => 0]);
        $order = $this->buy();

        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['status'], 'gift-1'))->handle();
        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['status'], 'gift-1'))->handle();

        $this->assertSame(150, app(Ledger::class)->balance($this->customer->id));
        $entry = Entry::where('type', 'bonus')->sole();
        $this->assertStringContainsString('Thank you for order', (string) $entry->note);
        $this->assertSame(RuleRun::SKIPPED, RuleRun::latest('id')->firstOrFail()->status);

        // Another event is another gift.
        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['status'], 'gift-2'))->handle();
        $this->assertSame(300, app(Ledger::class)->balance($this->customer->id));
    }

    public function test_a_guests_order_gets_nothing_and_says_so(): void
    {
        $rule = Rule::create(['name' => 'Thanks', 'event' => 'checkout.order.changed', 'enabled' => true, 'delay_minutes' => 0]);
        $rule->actions()->create(['type' => 'give_points', 'config' => ['points' => '150'], 'position' => 0]);
        $order = $this->buy();
        $order->forceFill(['user_id' => null])->save();

        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['status'], 'gift-3'))->handle();

        $this->assertSame(RuleRun::SKIPPED, RuleRun::latest('id')->firstOrFail()->status);
        $this->assertSame(0, Entry::where('type', 'bonus')->count());
    }

    protected function promotion(int $points): Promotion
    {
        $promotion = Promotion::create(['name' => ['en' => 'Points week'], 'zone' => 'cart', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => [['kind' => 'subtotal', 'operator' => 'subtotal_gte', 'value' => 0]]],
            'discount_type' => 'give_points', 'discount_value' => $points,
        ]);

        return $promotion;
    }

    protected function buy(): Order
    {
        $this->actingAs($this->customer)->post('http://localhost/cart/add/pg-lamp', ['qty' => 1]);
        $this->actingAs($this->customer)->post('http://localhost/checkout', [
            'customer_name' => 'Buyer', 'email' => $this->customer->email,
            'address_line' => '1 Street', 'city' => 'Town',
            'shipping_method_id' => (string) ShippingMethod::whereNull('provider')->where('enabled', true)->value('id'),
            'payment_method_id' => $this->cash->id,
        ])->assertSessionHasNoErrors();

        return Order::latest('id')->firstOrFail();
    }
}
