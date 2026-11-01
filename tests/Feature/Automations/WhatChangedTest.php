<?php

namespace Tests\Feature\Automations;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\RulePresets;
use Torgash\Automations\RuleTemplate;
use Torgash\Catalog\Models\Product;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Orders\OrderSource;

/**
 * What a rule can say about the change that fired it — what it was before
 * (`before.*`) and who made it (`actor.*`) — how an order was placed
 * (`order.source`, the "How it was placed" condition), and the ready-made
 * rules the module ships.
 */
class WhatChangedTest extends TestCase
{
    protected Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'sku' => 'WCH-1',
            'name' => ['en' => 'Changing widget'],
            'slug' => 'changing-widget',
            'price' => 50_00,
            'stock' => 10,
            'status' => 'active',
        ]);
    }

    public function test_a_status_change_says_what_it_was_and_which_manager_made_it(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('{}', 200)]);

        $rule = $this->rule('checkout.order_status_changed', '{{ before.status_label }} → {{ order.status_label }} ({{ actor.name }}, {{ actor.kind }})');
        $order = $this->order();
        $admin = User::role('admin')->firstOrFail();
        $to = OrderStatus::where('code', '!=', 'new')->firstOrFail();

        $this->actingAs($admin)
            ->patch('http://localhost/admin/orders/'.$order->order_number.'/status', ['status' => $to->code])
            ->assertOk();

        $job = $this->queued($rule);
        $this->assertSame('new', $job->moment['before']['status']);
        $job->handle();

        $new = (string) (OrderStatus::where('code', 'new')->first()?->t('name') ?: 'new');
        $this->assertBody($new.' → '.$to->t('name').' ('.$admin->name.', staff)');
    }

    public function test_an_edit_says_the_total_and_the_items_it_had(): void
    {
        Queue::fake();
        Http::fake(['*' => Http::response('{}', 200)]);

        $rule = $this->rule('checkout.order.changed', 'Was: {{ before.items_text }} — {{ before.total }}. Now: {{ order.items_text }} — {{ order.total }}. {{ changed_text }}. {{ actor.name }}');
        $order = $this->order();

        $items = $order->items;
        $items[0]['qty'] = 3;
        $items[0]['line_total'] = 150_00;
        $order->update(['items' => $items, 'total' => 150_00]);
        app(\Torgash\Checkout\Orders\OrderChanges::class)->flush();

        $this->queued($rule)->handle();

        // No session, nobody signed in: a job, a callback, the console.
        $this->assertBody('Was: Changing widget ×2 — 10000. Now: Changing widget ×3 — 15000. '
            .__('Total').', '.__('Contents').'. '.__('the system'));
    }

    public function test_a_field_that_did_not_change_was_what_it_is_now(): void
    {
        Http::fake(['*' => Http::response('{}', 200)]);

        // A test run fires with no moment at all: "was → now" still reads.
        $rule = $this->rule('checkout.order_status_changed', '{{ before.status }}/{{ order.status }}');
        $order = $this->order();

        (new RunRule($rule->id, $order->id, 'checkout.order_status_changed', ['status'], 'k-w'))->handle();

        $this->assertBody('new/new');
    }

    public function test_the_condition_on_how_an_order_was_placed(): void
    {
        Http::fake(['*' => Http::response('{}', 200)]);

        $rule = $this->rule('checkout.order_placed', '{{ order.source_label }}|{{#order.one_click}}1{{/order.one_click}}', [
            ['kind' => 'source', 'operator' => 'source_in', 'value' => ['list' => [OrderSource::ONE_CLICK]]],
        ]);

        $byManager = $this->order(['source' => OrderSource::ADMIN]);
        (new RunRule($rule->id, $byManager->id, 'checkout.order_placed', [], 'k-s1'))->handle();
        $this->assertSame(RuleRun::SKIPPED, RuleRun::latest('id')->value('status'));

        $oneClick = $this->order(['source' => OrderSource::ONE_CLICK]);
        (new RunRule($rule->id, $oneClick->id, 'checkout.order_placed', [], 'k-s2'))->handle();
        $this->assertBody(__('One click').'|1');
    }

    public function test_the_date_is_written_by_the_shops_clock(): void
    {
        Http::fake(['*' => Http::response('{}', 200)]);
        $rule = $this->rule('checkout.order_placed', '{{ order.date }}');
        $order = $this->order();
        $order->forceFill(['created_at' => \Carbon\Carbon::parse('2026-11-01 12:05:00')])->save();

        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-d'))->handle();

        $this->assertBody('01.11.2026 12:05');
    }

    public function test_every_ready_made_rule_imports_whole_and_switched_off(): void
    {
        $presets = RulePresets::all();
        $this->assertGreaterThanOrEqual(7, count($presets));

        foreach (array_keys($presets) as $slug) {
            $payload = RulePresets::payload($slug);
            $rule = RuleTemplate::import($payload, []);

            $this->assertFalse($rule->enabled, $slug);
            $this->assertSame($payload['event'], $rule->event, $slug);
            $this->assertCount(count($payload['conditions']), $rule->conditions, $slug.': a condition the schema does not know was dropped');
            $this->assertCount(count($payload['actions']), $rule->actions, $slug);
        }
    }

    public function test_a_ready_made_rule_takes_the_bot_the_shop_already_has(): void
    {
        $existing = Rule::create(['name' => 'Mine', 'enabled' => true, 'event' => 'checkout.order_placed', 'delay_minutes' => 0]);
        $existing->actions()->create(['type' => 'telegram', 'config' => ['token' => '123:abc', 'chat_id' => '-100', 'text' => 'x'], 'position' => 0]);

        $admin = User::role('admin')->firstOrFail();
        $this->actingAs($admin)->get('http://localhost/admin/automations/import')
            ->assertOk()->assertSee(RulePresets::all()['02-paid']['name']);
        $this->actingAs($admin)->post('http://localhost/admin/automations/import/review', ['preset' => '02-paid'])
            ->assertOk()->assertSee('order.total_formatted', false);

        $rule = RuleTemplate::import(RulePresets::payload('02-paid'), []);

        $this->assertSame('123:abc', $rule->actions->first()->config('token'));
        $this->assertSame('-100', $rule->actions->first()->config('chat_id'));
    }

    // ---------------------------------------------------------------- helpers

    protected function assertBody(string $expected): void
    {
        $bodies = Http::recorded()->map(fn ($pair) => $pair[0]->body())->all();
        $this->assertContains($expected, $bodies, 'sent: '.json_encode($bodies, JSON_UNESCAPED_UNICODE));
    }

    protected function queued(Rule $rule): RunRule
    {
        $found = null;
        Queue::assertPushed(RunRule::class, function (RunRule $job) use ($rule, &$found) {
            if ($job->ruleId === $rule->id) {
                $found = $job;
            }

            return true;
        });

        $this->assertNotNull($found, 'the rule was queued');

        return $found;
    }

    /** @param  list<array<string, mixed>>  $conditions */
    protected function rule(string $event, string $body, array $conditions = []): Rule
    {
        $rule = Rule::create(['name' => 'What changed', 'enabled' => true, 'event' => $event, 'fields' => null, 'delay_minutes' => 0]);

        foreach ($conditions as $position => $condition) {
            $rule->conditions()->create($condition + ['position' => $position]);
        }

        $rule->actions()->create([
            'type' => 'webhook',
            'config' => ['method' => 'POST', 'url' => 'https://hooks.example.test/x', 'headers' => '', 'body' => $body],
            'position' => 0,
        ]);

        return $rule->fresh(['conditions', 'actions']);
    }

    /** @param  array<string, mixed>  $overrides */
    protected function order(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'TG-WCH'.random_int(1000, 9999),
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
                'name' => 'Changing widget',
                'qty' => 2,
                'unit_price' => 50_00,
                'line_total' => 100_00,
            ]],
        ], $overrides))->fresh();
    }
}
