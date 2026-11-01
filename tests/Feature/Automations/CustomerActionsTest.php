<?php

namespace Tests\Feature\Automations;

use App\Core\Mail\EventMail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;
use Torgash\Automations\Actions\EmailAction;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Enquiries\Models\Enquiry;

/**
 * What a rule can do for and about the customer: write them a letter, leave
 * a note in the history, and ask whether this is their first order and
 * whether the goods are there.
 */
class CustomerActionsTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'CAT-1',
            'name' => ['en' => 'Acting widget'],
            'slug' => 'acting-widget',
            'price' => 50_00,
            'stock' => 10,
            'status' => 'active',
        ]);
    }

    public function test_a_letter_goes_to_the_customer_in_the_shops_frame(): void
    {
        Mail::fake();

        $rule = $this->rule('checkout.order_placed', 'email', ['subject' => 'Order {{ order.number }}: how to pay', 'text' => "Hello, {{ order.customer_name }}!\n\nPay {{ order.total_decimal | int }} to the account below."]);
        $order = $this->order();

        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-m'))->handle();

        $this->assertSame(RuleRun::SENT, RuleRun::latest('id')->value('status'));
        Mail::assertQueued(EventMail::class, function (EventMail $mail) use ($order) {
            $html = $mail->render();
            $mail->build();

            return $mail->event() === EmailAction::EVENT
                && $mail->hasTo('buyer@example.test')
                && $mail->subject === 'Order '.$order->order_number.': how to pay'
                && str_contains($html, '<p>Hello, Buyer!</p><p>Pay 100 to the account below.</p>');
        });
    }

    public function test_no_email_no_letter(): void
    {
        Mail::fake();

        $rule = $this->rule('checkout.order_placed', 'email', ['subject' => 'x', 'text' => 'y']);
        $order = $this->order(['email' => null, 'phone' => '+380671234567']);

        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-n'))->handle();

        $this->assertSame(RuleRun::SKIPPED, RuleRun::latest('id')->value('status'));
        Mail::assertNothingQueued();
    }

    public function test_a_note_goes_into_the_orders_history_and_the_enquirys_notes(): void
    {
        $rule = $this->rule('checkout.order_placed', 'note', ['text' => 'Reminder sent to {{ order.phone | phone }}']);
        $order = $this->order(['phone' => '+38 (067) 123-45-67']);

        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-o'))->handle();

        $log = OrderLog::where('order_id', $order->id)->latest('id')->firstOrFail();
        $this->assertSame('Reminder sent to +380671234567', $log->params['text']);
        $this->assertSame('Customer rule', $log->params['rule']);

        $enquiryRule = $this->rule('enquiries.enquiry.created', 'note', ['text' => 'Called {{ enquiry.name }}']);
        $enquiry = Enquiry::create(['kind' => 'callback', 'name' => 'Anna', 'phone' => '+380671234567', 'notes' => 'Earlier']);

        (new RunRule($enquiryRule->id, $enquiry->id, 'enquiries.enquiry.created', [], 'k-e', 'enquiry'))->handle();

        $this->assertMatchesRegularExpression('/^Earlier\n\[[0-9.: ]+\] Customer rule: Called Anna$/', (string) $enquiry->fresh()->notes);
    }

    public function test_first_and_repeat_orders(): void
    {
        $rule = $this->rule('checkout.order_placed', 'note', ['text' => 'first'], [
            ['kind' => 'customer_orders', 'operator' => 'first_order', 'value' => []],
        ]);

        $first = $this->order(['customer_ref' => 'phone:671234567']);
        $second = $this->order(['customer_ref' => 'phone:671234567']);

        (new RunRule($rule->id, $first->id, 'checkout.order_placed', [], 'k-f1'))->handle();
        $this->assertSame(RuleRun::SENT, RuleRun::latest('id')->value('status'));

        (new RunRule($rule->id, $second->id, 'checkout.order_placed', [], 'k-f2'))->handle();
        $this->assertSame(RuleRun::SKIPPED, RuleRun::latest('id')->value('status'));
    }

    public function test_an_item_not_in_stock(): void
    {
        $rule = $this->rule('checkout.order_placed', 'note', ['text' => 'short'], [
            ['kind' => 'stock', 'operator' => 'some_short', 'value' => []],
        ]);

        // Units taken and some left: in stock.
        $order = $this->order(['stock_deducted' => true]);
        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-s1'))->handle();
        $this->assertSame(RuleRun::SKIPPED, RuleRun::latest('id')->value('status'));

        // Sold past the shelf: short.
        $this->product->forceFill(['stock' => -1])->save();
        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-s2'))->handle();
        $this->assertSame(RuleRun::SENT, RuleRun::latest('id')->value('status'));

        // Holding no units while one is left for a line of two: short as well.
        $this->product->forceFill(['stock' => 1])->save();
        $waiting = $this->order(['stock_deducted' => false]);
        (new RunRule($rule->id, $waiting->id, 'checkout.order_placed', [], 'k-s3'))->handle();
        $this->assertSame(RuleRun::SENT, RuleRun::latest('id')->value('status'));
    }

    /**
     * What the order leaves on the shelf, said in the order's own message —
     * after this order, as if its units were taken even while it waits for
     * payment, against the automations' threshold.
     */
    public function test_an_order_says_which_products_it_leaves_running_low(): void
    {
        app(\App\Core\Settings\SettingsManager::class)->set('torgash.automations', 'low_stock_threshold', 2);

        $rule = $this->rule('checkout.order_placed', 'note', [
            'text' => '{{#order.low_stock_count}}Low: {{/order.low_stock_count}}{{#order.items}}{{#item.low_stock}}{{ item.name }} {{ item.stock }} {{ item.stock_state }}{{/item.low_stock}}{{/order.items}}{{^order.low_stock_count}}enough{{/order.low_stock_count}}',
        ], [
            ['kind' => 'stock', 'operator' => 'some_low', 'value' => []],
        ]);

        // 10 on the shelf, 2 in the order: 8 left — enough, the rule is skipped.
        $order = $this->order();
        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-l1'))->handle();
        $this->assertSame(RuleRun::SKIPPED, RuleRun::latest('id')->value('status'));

        // 3 on the shelf, 2 in an order not yet deducted: 1 left — low.
        $this->product->forceFill(['stock' => 3])->save();
        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-l2'))->handle();
        $this->assertSame('Low: Acting widget 1 low', OrderLog::where('order_id', $order->id)->latest('id')->value('params')['text']);

        // Deducted and nothing left: out.
        $this->product->forceFill(['stock' => 0])->save();
        $order->forceFill(['stock_deducted' => true])->save();
        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-l3'))->handle();
        $this->assertSame('Low: Acting widget 0 out', OrderLog::where('order_id', $order->id)->latest('id')->value('params')['text']);

        $context = \Torgash\Automations\Context::forOrder($order->fresh(), 'checkout.order_placed');
        $this->assertSame(1, $context['order']['low_stock_count']);
        $this->assertSame('Acting widget — '.__(':count pcs.', ['count' => 0]), $context['order']['low_stock_text']);
    }

    public function test_every_ready_made_rule_still_imports_with_its_conditions(): void
    {
        $payload = \Torgash\Automations\RulePresets::payload('08-low-stock');
        $rule = \Torgash\Automations\RuleTemplate::import($payload, []);

        $this->assertSame('some_low', $rule->conditions->first()->operator);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string, string>  $config
     * @param  list<array<string, mixed>>  $conditions
     */
    protected function rule(string $event, string $type, array $config, array $conditions = []): Rule
    {
        $rule = Rule::create(['name' => 'Customer rule', 'enabled' => true, 'event' => $event, 'fields' => null, 'delay_minutes' => 0]);

        foreach ($conditions as $position => $condition) {
            $rule->conditions()->create($condition + ['position' => $position]);
        }

        $rule->actions()->create(['type' => $type, 'config' => $config, 'position' => 0]);

        return $rule->fresh(['conditions', 'actions']);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TG-CAT'.random_int(1000, 9999),
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'city' => 'Testville',
            'country' => 'UA',
            'currency_code' => 'USD',
            'subtotal' => 100_00,
            'shipping_cost' => 0,
            'total' => 100_00,
            'status' => 'new',
            'stock_deducted' => false,
            'items' => [[
                'product_id' => $this->product->id,
                'sku' => $this->product->sku,
                'name' => 'Acting widget',
                'qty' => 2,
                'unit_price' => 50_00,
                'line_total' => 100_00,
            ]],
        ], $overrides))->fresh();
    }
}
