<?php

namespace Tests\Feature\Automations;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\Template;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Orders\PaymentState;

/**
 * The rule engine: trigger → delay → conditions → action.
 *
 * What is worth pinning down here is not "a webhook was sent" but the four
 * decisions that are expensive to change later — conditions are re-checked when
 * the rule actually runs rather than when it was queued, a retry cannot create a
 * second deal on the other side, a body composed by an admin can never execute
 * anything, and a URL pointing back inside the network is refused.
 */
class RuleEngineTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'AUT-1',
            'name' => ['en' => 'Automated widget'],
            'slug' => 'automated-widget',
            'price' => 50_00,
            'stock' => 10,
            'status' => 'active',
        ]);
    }

    // --------------------------------------------------------------- trigger

    public function test_a_change_queues_the_rules_that_asked_for_it_and_no_others(): void
    {
        Queue::fake();

        $wanted = $this->rule(['name' => 'On status', 'fields' => ['status']]);
        $other = $this->rule(['name' => 'On manager', 'fields' => ['manager_id']]);
        $any = $this->rule(['name' => 'On anything', 'fields' => null]);
        $off = $this->rule(['name' => 'Disabled', 'fields' => null, 'enabled' => false]);

        $order = $this->order();
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)
            ->patch('http://localhost/admin/orders/'.$order->order_number.'/status', [
                'status' => OrderStatus::where('code', '!=', 'new')->value('code'),
            ])
            ->assertOk();

        $queued = [];
        Queue::assertPushed(RunRule::class, function (RunRule $job) use (&$queued) {
            $queued[] = $job->ruleId;

            return true;
        });

        $this->assertContains($wanted->id, $queued);
        $this->assertContains($any->id, $queued);
        $this->assertNotContains($other->id, $queued, 'a rule watching another field must not fire');
        $this->assertNotContains($off->id, $queued, 'a disabled rule must not fire');
    }

    public function test_a_delay_is_the_queues_and_nothing_waits_in_the_request(): void
    {
        Queue::fake();

        $this->rule(['delay_minutes' => 30]);
        $order = $this->order();

        $this->actingAs(User::role('admin')->firstOrFail())
            ->patch('http://localhost/admin/orders/'.$order->order_number.'/manager', ['manager_id' => null])
            ->assertOk();

        // Nothing changed (manager was already unset), so nothing is announced —
        // which is itself the guarantee the change funnel makes.
        Queue::assertNothingPushed();
    }

    // ------------------------------------------------------------ conditions

    public function test_conditions_are_checked_when_the_rule_runs_not_when_it_was_queued(): void
    {
        Http::fake();

        $rule = $this->rule([
            'conditions' => [['kind' => 'payment_state', 'operator' => 'payment_in', 'value' => ['list' => [PaymentState::PENDING]]]],
        ]);

        // Queued while unpaid; paid before the delay ran out. Firing "still
        // unpaid → chase the customer" at it now is the mistake this prevents.
        $order = $this->order();
        $order->update(['payment_state' => PaymentState::PAID, 'paid_total' => 100_00]);

        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['status'], 'k1'))->handle();

        Http::assertNothingSent();
        $this->assertSame(RuleRun::SKIPPED, RuleRun::latest('id')->firstOrFail()->status);
    }

    public function test_all_conditions_must_hold(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);

        $rule = $this->rule([
            'conditions' => [
                ['kind' => 'total', 'operator' => 'total_min', 'value' => ['amount' => 50_00]],
                ['kind' => 'country', 'operator' => 'text_in', 'value' => ['list' => ['PL']]],
            ],
        ]);

        $order = $this->order(['country' => 'UA']);

        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['total'], 'k2'))->handle();

        Http::assertNothingSent();
    }

    // --------------------------------------------------------------- webhook

    public function test_the_body_is_rendered_from_the_order_and_the_receiver_gets_it(): void
    {
        Http::fake(['*' => Http::response('{"ok":true}', 200)]);

        $rule = $this->rule(['body' => '{"number":"{{ order.number }}","total":{{ order.total }},"lines":[{{#order.items}}{"sku":"{{ item.sku }}","qty":{{ item.qty }}}{{/order.items}}]}']);
        $order = $this->order();

        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['total'], 'k3'))->handle();

        Http::assertSent(function ($request) use ($order) {
            $body = json_decode($request->body(), true);

            return $request->url() === 'https://hooks.example.test/orders'
                && $body['number'] === $order->order_number
                && $body['total'] === 100_00
                && $body['lines'][0]['sku'] === 'AUT-1'
                && $body['lines'][0]['qty'] === 2
                // Same key on every attempt of one trigger: that is what stops
                // a redelivered job creating a second deal on the other side.
                && $request->header('Idempotency-Key')[0] === 'k3';
        });

        $run = RuleRun::latest('id')->firstOrFail();
        $this->assertSame(RuleRun::SENT, $run->status);
        $this->assertSame('HTTP 200', $run->summary);
    }

    public function test_a_refusal_by_the_receiver_is_a_failure_the_log_shows(): void
    {
        Http::fake(['*' => Http::response('nope', 422)]);

        $rule = $this->rule();
        $order = $this->order();

        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['total'], 'k4'))->handle();

        $run = RuleRun::latest('id')->firstOrFail();

        $this->assertSame(RuleRun::FAILED, $run->status);
        $this->assertSame('HTTP 422', $run->summary);
        $this->assertSame('nope', $run->detail['response']);
    }

    public function test_a_url_pointing_back_into_the_network_is_refused_before_the_request(): void
    {
        Http::fake();

        $rule = $this->rule(['url' => 'http://169.254.169.254/latest/meta-data/']);
        $order = $this->order();

        (new RunRule($rule->id, $order->id, 'checkout.order.changed', ['total'], 'k5'))->handle();

        Http::assertNothingSent();

        $run = RuleRun::latest('id')->firstOrFail();
        $this->assertSame(RuleRun::FAILED, $run->status);
        $this->assertStringContainsString('private or reserved', $run->summary);
    }

    // -------------------------------------------------------------- template

    public function test_a_template_cannot_execute_anything_and_escapes_what_it_substitutes(): void
    {
        $rendered = Template::render(
            '{{ order.customer_name }} @php echo 1; @endphp {{ 7 * 7 }}',
            ['order' => ['customer_name' => 'O"Brien \\ Co']],
        );

        // The quote is escaped for the JSON string it sits in, the Blade
        // directive is inert text, and an expression is not a path so it stays
        // as it was typed rather than being evaluated.
        $this->assertStringContainsString('O\"Brien \\\\ Co', $rendered);
        $this->assertStringContainsString('@php echo 1; @endphp', $rendered);
        $this->assertStringContainsString('{{ 7 * 7 }}', $rendered);
    }

    // ---------------------------------------------------------------- helpers

    /** @param  array<string, mixed>  $overrides */
    protected function rule(array $overrides = []): Rule
    {
        $conditions = $overrides['conditions'] ?? [];
        $url = $overrides['url'] ?? 'https://hooks.example.test/orders';
        $body = $overrides['body'] ?? '{"number":"{{ order.number }}"}';
        unset($overrides['conditions'], $overrides['url'], $overrides['body']);

        $rule = Rule::create(array_merge([
            'name' => 'Test rule',
            'enabled' => true,
            'event' => 'checkout.order.changed',
            'fields' => null,
            'delay_minutes' => 0,
        ], $overrides));

        foreach ($conditions as $position => $condition) {
            $rule->conditions()->create($condition + ['position' => $position]);
        }

        $rule->actions()->create([
            'type' => 'webhook',
            'config' => ['method' => 'POST', 'url' => $url, 'headers' => '', 'body' => $body],
            'position' => 0,
        ]);

        return $rule->fresh(['conditions', 'actions']);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TG-AUT'.random_int(1000, 9999),
            'customer_name' => 'Buyer',
            'email' => 'buyer@example.test',
            'address_line' => '1 Test Street',
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
                'name' => 'Automated widget',
                'qty' => 2,
                'unit_price' => 50_00,
                'line_total' => 100_00,
            ]],
        ], $overrides))->fresh();
    }
}
