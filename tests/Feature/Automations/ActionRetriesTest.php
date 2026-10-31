<?php

namespace Tests\Feature\Automations;

use App\Core\Settings\SettingsManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Checkout\Models\Order;

/**
 * A receiver that could not be reached is tried again — that one action,
 * after the shop's interval, as many times as the shop allows — while a
 * refusal is final. For every action alike: the Telegram message and the
 * webhook here, a module's own action through the same `retry` answer.
 */
class ActionRetriesTest extends TestCase
{
    protected function order(): Order
    {
        return Order::create([
            'order_number' => 'TG-RTR'.random_int(1000, 9999),
            'customer_name' => 'Buyer', 'email' => 'buyer@example.test', 'phone' => '+380675550128',
            'city' => 'Kyiv', 'country' => 'UA', 'currency_code' => 'USD',
            'subtotal' => 100_00, 'shipping_cost' => 0, 'total' => 100_00, 'status' => 'new', 'stock_deducted' => false,
            'items' => [['sku' => 'W-1', 'name' => 'Widget', 'qty' => 1, 'unit_price' => 100_00, 'line_total' => 100_00]],
        ])->fresh();
    }

    /** A rule with a status change FIRST and the Telegram message after it. */
    protected function rule(): Rule
    {
        $rule = Rule::create(['name' => 'Retries', 'enabled' => true, 'event' => 'checkout.order_placed', 'delay_minutes' => 0]);
        $rule->actions()->create(['type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test/in', 'method' => 'POST', 'body' => '{}'], 'position' => 0]);
        $rule->actions()->create(['type' => 'telegram', 'config' => ['token' => '123:SECRET', 'chat_id' => '-100500', 'text' => 'Order {{ order.number }}'], 'position' => 1]);

        return $rule;
    }

    public function test_a_dropped_connection_queues_that_one_action_again_and_the_retry_delivers(): void
    {
        app(SettingsManager::class)->set('torgash.automations', 'retries', 3);
        app(SettingsManager::class)->set('torgash.automations', 'retry_minutes', 2);
        Queue::fake();
        // Telegram: the connection drops three times (the action's own
        // at-once retries use them up), then answers. One fake: a second
        // Http::fake() adds a stub rather than replacing the first.
        $telegramCalls = 0;
        Http::fake([
            'hooks.example.test/*' => Http::response('ok', 200),
            'api.telegram.org/*' => function () use (&$telegramCalls) {
                if (++$telegramCalls <= 3) {
                    throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: Operation timed out');
                }

                return Http::response(['ok' => true, 'result' => []]);
            },
        ]);

        $order = $this->order();
        $rule = $this->rule();
        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-rtr'))->handle();

        // The webhook went; the message is marked for a retry, not failed.
        $runs = RuleRun::where('rule_id', $rule->id)->orderBy('id')->get();
        $this->assertSame([RuleRun::SENT, RuleRun::RETRY], $runs->pluck('status')->all());
        $this->assertStringContainsString('1 of 4', $runs[1]->summary);
        $this->assertStringContainsString('cURL error 28', $runs[1]->summary);

        // One job, for the Telegram action alone, two minutes on.
        $telegram = $rule->actions()->where('type', 'telegram')->value('id');
        $this->assertCount(1, Queue::pushedJobs()[RunRule::class] ?? []);
        Queue::assertPushed(RunRule::class, fn (RunRule $job) => $job->onlyActionId === (int) $telegram
            && $job->attempt === 2 && $job->idempotencyKey === 'k-rtr' && $job->delay !== null);

        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'hooks.example.test')));

        // The retry: Telegram answers, the webhook is NOT sent a second time.
        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-rtr', onlyActionId: (int) $telegram, attempt: 2))->handle();

        $this->assertCount(1, Http::recorded(fn ($request) => str_contains($request->url(), 'hooks.example.test')));
        $this->assertSame(4, $telegramCalls);
        $last = RuleRun::where('rule_id', $rule->id)->orderBy('id')->get()->last();
        $this->assertSame(RuleRun::SENT, $last->status);
        $this->assertSame(2, $last->detail['attempt']);
    }

    public function test_the_last_attempt_fails_for_good_and_no_retries_means_failed_at_once(): void
    {
        app(SettingsManager::class)->set('torgash.automations', 'retries', 2);
        Queue::fake();
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Too Many Requests'], 429)]);

        $order = $this->order();
        $rule = $this->rule();
        $telegram = (int) $rule->actions()->where('type', 'telegram')->value('id');

        // Attempt 3 of 3: nothing more is queued, the row says failed.
        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-last', onlyActionId: $telegram, attempt: 3))->handle();
        Queue::assertNothingPushed();
        $this->assertSame(RuleRun::FAILED, RuleRun::where('rule_id', $rule->id)->latest('id')->value('status'));

        app(SettingsManager::class)->set('torgash.automations', 'retries', 0);
        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-none'))->handle();
        Queue::assertNothingPushed();
        $this->assertSame(RuleRun::FAILED, RuleRun::where('rule_id', $rule->id)->latest('id')->value('status'));
    }

    public function test_a_refusal_is_never_retried(): void
    {
        app(SettingsManager::class)->set('torgash.automations', 'retries', 3);
        Queue::fake();
        Http::fake([
            'hooks.example.test/*' => Http::response('no such hook', 404),
            'api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'Bad Request: chat not found'], 400),
        ]);

        $order = $this->order();
        $rule = $this->rule();
        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-ref'))->handle();

        Queue::assertNothingPushed();
        $this->assertSame([RuleRun::FAILED, RuleRun::FAILED], RuleRun::where('rule_id', $rule->id)->orderBy('id')->pluck('status')->all());
    }

    public function test_a_receivers_own_error_on_a_webhook_comes_round_again_with_the_same_key(): void
    {
        app(SettingsManager::class)->set('torgash.automations', 'retries', 1);
        Queue::fake();
        Http::fake([
            'hooks.example.test/*' => Http::response('boom', 503),
            'api.telegram.org/*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $order = $this->order();
        $rule = $this->rule();
        (new RunRule($rule->id, $order->id, 'checkout.order_placed', [], 'k-503'))->handle();

        $webhook = (int) $rule->actions()->where('type', 'webhook')->value('id');
        $this->assertSame([RuleRun::RETRY, RuleRun::SENT], RuleRun::where('rule_id', $rule->id)->orderBy('id')->pluck('status')->all());
        Queue::assertPushed(RunRule::class, fn (RunRule $job) => $job->onlyActionId === $webhook && $job->idempotencyKey === 'k-503');
    }
}
