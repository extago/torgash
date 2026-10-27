<?php

namespace Tests\Feature\Automations;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Http\Client\Request as SentRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Torgash\Automations\Jobs\RunRule;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\Models\RuleRun;
use Torgash\Automations\Subjects\Subjects;
use Torgash\Enquiries\Models\Enquiry;
use Torgash\Enquiries\Models\Message;
use Torgash\Enquiries\Overdue;
use Torgash\Enquiries\Status;

/**
 * Rules about an ENQUIRY: the enquiries module registers the subject, and the
 * engine runs it exactly as it runs an order — its events, its conditions,
 * its variables, its own actions, the same guard against rules chaining.
 */
class EnquiryRulesTest extends TestCase
{
    protected function enquiry(array $attributes = []): Enquiry
    {
        return Enquiry::create($attributes + [
            'kind' => 'callback',
            'name' => 'Олена',
            'phone' => '+380501112233',
            'fields' => ['values' => ['phone' => '+380501112233', 'comment' => 'After 18:00'], 'labels' => ['phone' => 'Phone', 'comment' => 'Comment']],
        ]);
    }

    protected function rule(string $event, array $actions, array $conditions = []): Rule
    {
        $rule = Rule::create(['name' => 'Enquiry rule', 'enabled' => true, 'event' => $event, 'delay_minutes' => 0]);
        foreach ($conditions as $i => $condition) {
            $rule->conditions()->create($condition + ['position' => $i]);
        }
        foreach ($actions as $i => [$type, $config]) {
            $rule->actions()->create(['type' => $type, 'config' => $config, 'position' => $i]);
        }

        return $rule;
    }

    public function test_the_enquiry_is_a_subject_with_its_own_events(): void
    {
        $this->assertSame('enquiry', Subjects::keyFor('enquiries.enquiry.created'));
        $this->assertSame('order', Subjects::keyFor('checkout.order_placed'));
    }

    public function test_an_arriving_enquiry_queues_the_rules_that_wait_for_it(): void
    {
        Queue::fake();
        $rule = $this->rule('enquiries.enquiry.created', [['webhook', ['url' => 'https://hooks.example.test/x', 'method' => 'POST', 'headers' => '', 'body' => '{}']]]);

        $enquiry = $this->enquiry();
        hook_action('enquiries.enquiry.created', ['enquiry' => $enquiry]);

        Queue::assertPushed(RunRule::class, fn (RunRule $job) => $job->ruleId === $rule->id
            && $job->subject === 'enquiry' && $job->subjectId === $enquiry->id);
    }

    public function test_a_telegram_message_prints_the_enquirys_own_variables(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
        $enquiry = $this->enquiry();
        $rule = $this->rule('enquiries.enquiry.created', [['telegram', [
            'token' => '1:X', 'chat_id' => '-1',
            'text' => "{{ enquiry.kind_label }} {{ enquiry.number }}: {{ enquiry.name }}, {{ enquiry.phone }}\n{{ enquiry.text }}",
        ]]]);

        (new RunRule($rule->id, $enquiry->id, 'enquiries.enquiry.created', [], 'k', 'enquiry'))->handle();

        Http::assertSent(fn (SentRequest $request) => str_contains($request['text'], '#'.$enquiry->id.': Олена, +380501112233')
            && str_contains($request['text'], 'Comment: After 18:00'));
        $this->assertSame(RuleRun::SENT, RuleRun::where('rule_id', $rule->id)->value('status'));
        $this->assertSame('enquiry', RuleRun::where('rule_id', $rule->id)->value('subject'));
    }

    public function test_conditions_are_the_enquirys(): void
    {
        Http::fake();
        $enquiry = $this->enquiry(['kind' => 'product_question', 'email' => 'x@example.test']);
        $rule = $this->rule('enquiries.enquiry.created', [['telegram', ['token' => '1:X', 'chat_id' => '-1', 'text' => 'Hi']]], [
            ['kind' => 'kind', 'operator' => 'in', 'value' => ['list' => ['callback']]],
        ]);

        (new RunRule($rule->id, $enquiry->id, 'enquiries.enquiry.created', [], 'k', 'enquiry'))->handle();

        Http::assertNothingSent();
        $this->assertSame(RuleRun::SKIPPED, RuleRun::where('rule_id', $rule->id)->value('status'));
    }

    public function test_the_enquirys_own_actions_move_it_and_an_orders_action_is_refused(): void
    {
        $staff = User::role('admin')->firstOrFail();
        $enquiry = $this->enquiry();
        $rule = $this->rule('enquiries.enquiry.created', [
            ['set_enquiry_status', ['status' => Status::IN_PROGRESS]],
            ['set_enquiry_manager', ['manager_id' => (string) $staff->id]],
            ['set_status', ['status' => 'new']],
        ]);

        (new RunRule($rule->id, $enquiry->id, 'enquiries.enquiry.created', [], 'k', 'enquiry'))->handle();

        $enquiry->refresh();
        $this->assertSame(Status::IN_PROGRESS, $enquiry->status);
        $this->assertSame($staff->id, $enquiry->manager_id);
        $this->assertSame(RuleRun::FAILED, RuleRun::where('rule_id', $rule->id)->latest('id')->value('status'));
    }

    /** A status change announced by the model triggers rules — but not one a rule itself made. */
    public function test_a_status_change_triggers_and_a_rules_own_change_does_not(): void
    {
        Queue::fake();
        $rule = $this->rule('enquiries.enquiry.status_changed', [['set_enquiry_status', ['status' => Status::DONE]]]);
        $enquiry = $this->enquiry();

        $enquiry->update(['name' => 'Renamed']);
        Queue::assertNotPushed(RunRule::class);

        $enquiry->update(['status' => Status::IN_PROGRESS]);
        Queue::assertPushed(RunRule::class, 1);

        // Running it moves the status again, which must not queue the rule anew.
        (new RunRule($rule->id, $enquiry->id, 'enquiries.enquiry.status_changed', ['status'], 'k', 'enquiry'))->handle();
        $this->assertSame(Status::DONE, $enquiry->fresh()->status);
        Queue::assertPushed(RunRule::class, 1);
    }

    public function test_a_late_enquiry_is_announced_once_and_again_after_the_customer_writes(): void
    {
        app(SettingsManager::class)->set('torgash.enquiries', 'reply_within_hours', 2);
        $announced = [];
        hook_listen('enquiries.enquiry.overdue', function (array $context) use (&$announced) {
            $announced[] = $context['enquiry']->id;
        });

        $late = $this->enquiry();
        Enquiry::where('id', $late->id)->update(['created_at' => now()->subHours(3)]);
        $fresh = $this->enquiry();

        Overdue::sweep();
        Overdue::sweep();
        $this->assertSame([$late->id], $announced);

        // Time passes: that announcement was made hours ago; then the shop
        // answers and the customer writes back — a new wait begins.
        Enquiry::where('id', $late->id)->update(['overdue_announced_at' => now()->subHours(4)]);
        Message::create(['enquiry_id' => $late->id, 'is_staff' => true, 'body' => 'Hi', 'created_at' => now()->subHours(3)]);
        Message::create(['enquiry_id' => $late->id, 'is_staff' => false, 'body' => 'And?', 'created_at' => now()->subHours(2)->subMinute()]);

        Overdue::sweep();
        $this->assertSame([$late->id, $late->id], $announced);
        $this->assertNotContains($fresh->id, $announced);
    }

    public function test_the_form_keeps_only_what_the_rules_subject_understands(): void
    {
        $rule = $this->rule('enquiries.enquiry.created', []);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->put("http://localhost/admin/automations/{$rule->id}", [
                'name' => 'Callbacks', 'event' => 'enquiries.enquiry.created', 'enabled' => 1,
                'conditions' => [
                    ['kind' => 'kind', 'operator' => 'in', 'list' => ['callback']],
                    ['kind' => 'total', 'operator' => 'total_min', 'amount' => '10'],
                ],
                'actions' => [
                    ['type' => 'set_enquiry_status', 'config' => ['status' => Status::IN_PROGRESS]],
                    ['type' => 'set_status', 'config' => ['status' => 'new']],
                ],
            ])
            ->assertRedirect();

        $this->assertSame(['kind'], $rule->conditions()->pluck('kind')->all());
        $this->assertSame(['set_enquiry_status'], $rule->actions()->pluck('type')->all());
        $this->assertSame(Status::IN_PROGRESS, $rule->actions()->first()->config('status'));

        $this->get("http://localhost/admin/automations/{$rule->id}/edit")
            ->assertOk()
            ->assertSee('enquiry.phone')
            ->assertSee('enquiries.enquiry.overdue');
    }
}
