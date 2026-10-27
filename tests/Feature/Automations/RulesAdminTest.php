<?php

namespace Tests\Feature\Automations;

use App\Models\User;
use Tests\TestCase;
use Torgash\Automations\Models\Rule;

/**
 * The screens: a rule can be written, read back and corrected without touching
 * the database. Cheap, but it is what catches a form that stopped saving half
 * of itself — and the conditions/actions editor is exactly the kind of thing
 * that does.
 */
class RulesAdminTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_a_rule_can_be_created_with_its_conditions_and_action(): void
    {
        $this->actingAs($this->admin)
            ->post('http://localhost/admin/automations', [
                'name' => 'Tell the CRM',
                'event' => 'checkout.order.changed',
                'fields' => ['status', 'payment_state'],
                'delay_minutes' => 15,
                'enabled' => '1',
                'conditions' => [
                    ['kind' => 'total', 'operator' => 'total_min', 'amount' => '500'],
                    ['kind' => 'country', 'operator' => 'text_in', 'text' => 'UA, PL'],
                    // Refused rather than stored: nothing could evaluate it.
                    ['kind' => 'total', 'operator' => 'no_such_operator', 'amount' => '1'],
                ],
                'actions' => [
                    ['type' => 'webhook', 'method' => 'POST', 'url' => 'https://hooks.example.test/x', 'headers' => 'Authorization: Bearer t', 'body' => '{}'],
                    ['type' => 'nothing_like_this', 'url' => 'https://evil.example.test'],
                ],
            ])
            ->assertRedirect();

        $rule = Rule::with(['conditions', 'actions'])->firstOrFail();

        $this->assertSame(['status', 'payment_state'], $rule->fields);
        $this->assertSame(15, $rule->delay_minutes);
        $this->assertCount(2, $rule->conditions);
        $this->assertSame(500_00, $rule->conditions[0]->amount());
        $this->assertSame(['UA', 'PL'], $rule->conditions[1]->list());
        $this->assertCount(1, $rule->actions);
        $this->assertSame('https://hooks.example.test/x', $rule->actions[0]->config('url'));
    }

    public function test_a_field_filter_is_not_kept_for_an_event_that_carries_no_diff(): void
    {
        $this->actingAs($this->admin)
            ->post('http://localhost/admin/automations', [
                'name' => 'On placement',
                'event' => 'checkout.order_placed',
                'fields' => ['status'],
                'enabled' => '1',
            ])
            ->assertRedirect();

        // Keeping it would be a rule that can never fire.
        $this->assertNull(Rule::firstOrFail()->fields);
    }

    public function test_the_screens_render(): void
    {
        $rule = Rule::create([
            'name' => 'Something', 'event' => 'checkout.order.changed', 'enabled' => true, 'delay_minutes' => 0,
        ]);
        $rule->conditions()->create(['kind' => 'total', 'operator' => 'total_min', 'value' => ['amount' => 1000], 'position' => 0]);
        $rule->actions()->create(['type' => 'webhook', 'config' => ['url' => 'https://hooks.example.test/x'], 'position' => 0]);

        $this->actingAs($this->admin)->get('http://localhost/admin/automations')->assertOk()->assertSee('Something');
        $this->actingAs($this->admin)->get('http://localhost/admin/automations/create')->assertOk();
        $this->actingAs($this->admin)->get('http://localhost/admin/automations/'.$rule->id.'/edit')->assertOk();
        $this->actingAs($this->admin)->get('http://localhost/admin/automations/runs')->assertOk();
    }

    public function test_the_screens_are_closed_to_somebody_without_the_permission(): void
    {
        $customer = User::create(['name' => 'Buyer', 'email' => 'buyer@example.test', 'password' => 'secret123']);

        $this->actingAs($customer)->get('http://localhost/admin/automations')->assertForbidden();
    }
}
