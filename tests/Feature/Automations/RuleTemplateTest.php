<?php

namespace Tests\Feature\Automations;

use App\Models\User;
use Tests\TestCase;
use Torgash\Automations\Models\Rule;
use Torgash\Automations\RuleTemplate;

/**
 * Sharing a rule.
 *
 * The one thing that must never go wrong: a template posted on a forum cannot
 * carry the key or the endpoint of the shop that wrote it. Everything else here
 * is about the import being honest — it arrives switched off, and anything this
 * shop cannot run is dropped rather than stored to fail later.
 */
class RuleTemplateTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    public function test_an_exported_template_carries_the_body_but_no_url_and_no_key(): void
    {
        $rule = $this->rule();

        $payload = RuleTemplate::export($rule);
        $json = json_encode($payload);

        $this->assertStringNotContainsString('hooks.example.test', $json);
        $this->assertStringNotContainsString('sk_live_SECRET', $json);

        // The shape is what a template is for, and it survives whole.
        $this->assertSame('checkout.order.changed', $payload['event']);
        $this->assertSame(15, $payload['delay_minutes']);
        $this->assertSame('{"number":"{{ order.number }}"}', $payload['actions'][0]['body']);
        $this->assertSame('total', $payload['conditions'][0]['kind']);

        // The header NAME travels, so the importer knows what to be asked for.
        $this->assertStringContainsString('Authorization:', $payload['actions'][0]['headers']);
        $this->assertSame('', $payload['actions'][0]['url']);

        $paths = array_column(RuleTemplate::needs($payload), 'path');
        $this->assertContains('actions.0.url', $paths);
        $this->assertContains('actions.0.headers.Authorization', $paths);
    }

    public function test_the_download_is_json_and_attaches_itself(): void
    {
        $rule = $this->rule();

        $response = $this->actingAs($this->admin)
            ->get('http://localhost/admin/automations/'.$rule->id.'/export')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json');

        $this->assertStringContainsString('attachment; filename="automation-', $response->headers->get('Content-Disposition'));
    }

    public function test_importing_asks_for_the_missing_parts_and_puts_them_back(): void
    {
        $json = json_encode(RuleTemplate::export($this->rule()));
        Rule::query()->delete();

        // Step one: the review screen names what the template does not carry.
        $this->actingAs($this->admin)
            ->post('http://localhost/admin/automations/import/review', ['json' => $json])
            ->assertOk()
            ->assertSee('answers[actions.0.headers.Authorization]', false);

        // Step two: the answers go in, and only there.
        $this->actingAs($this->admin)
            ->post('http://localhost/admin/automations/import', [
                'json' => $json,
                'answers' => [
                    'actions.0.url' => 'https://mine.example.test/hook',
                    'actions.0.headers.Authorization' => 'Bearer mine',
                ],
            ])
            ->assertRedirect();

        $rule = Rule::with(['conditions', 'actions'])->firstOrFail();

        $this->assertSame('https://mine.example.test/hook', $rule->actions[0]->config('url'));
        // X-Shop was asked for too and left blank, so it is dropped rather than
        // sent as an empty header.
        $this->assertSame('Authorization: Bearer mine', $rule->actions[0]->config('headers'));
        $this->assertSame('{"number":"{{ order.number }}"}', $rule->actions[0]->config('body'));
        $this->assertCount(1, $rule->conditions);
        $this->assertSame(15, $rule->delay_minutes);
    }

    public function test_an_imported_rule_arrives_switched_off(): void
    {
        $json = json_encode(RuleTemplate::export($this->rule(['enabled' => true])));
        Rule::query()->delete();

        $this->actingAs($this->admin)
            ->post('http://localhost/admin/automations/import', ['json' => $json, 'answers' => []])
            ->assertRedirect();

        // It points at an endpoint nobody has tested from this shop yet; its
        // first act must not be a wrong POST about a real order.
        $this->assertFalse(Rule::firstOrFail()->enabled);
    }

    public function test_what_this_shop_cannot_run_is_dropped_rather_than_stored(): void
    {
        $payload = [
            'torgash_automation' => 1,
            'name' => 'From somewhere else',
            'event' => 'someone.elses.event',
            'delay_minutes' => 5,
            'conditions' => [
                ['kind' => 'total', 'operator' => 'total_min', 'value' => ['amount' => 100]],
                ['kind' => 'moon_phase', 'operator' => 'is_full', 'value' => []],
            ],
            'actions' => [
                ['type' => 'webhook', 'method' => 'POST', 'url' => '', 'headers' => '', 'body' => '{}'],
                ['type' => 'send_a_pigeon', 'method' => 'POST', 'url' => '', 'headers' => '', 'body' => ''],
            ],
        ];

        $rule = RuleTemplate::import($payload, ['actions.0.url' => 'https://mine.example.test/hook']);

        $this->assertSame('checkout.order.changed', $rule->event, 'an unknown event falls back rather than being stored');
        $this->assertCount(1, $rule->conditions);
        $this->assertCount(1, $rule->actions);
    }

    public function test_something_that_is_not_a_template_is_refused(): void
    {
        $this->actingAs($this->admin)
            ->post('http://localhost/admin/automations/import/review', ['json' => '{"hello":"world"}'])
            ->assertRedirect()
            ->assertSessionHasErrors('json');
    }

    /** @param  array<string, mixed>  $overrides */
    protected function rule(array $overrides = []): Rule
    {
        $rule = Rule::create(array_merge([
            'name' => 'Tell the CRM',
            'event' => 'checkout.order.changed',
            'fields' => ['status'],
            'delay_minutes' => 15,
            'enabled' => false,
        ], $overrides));

        $rule->conditions()->create([
            'kind' => 'total', 'operator' => 'total_min', 'value' => ['amount' => 500_00], 'position' => 0,
        ]);

        $rule->actions()->create([
            'type' => 'webhook',
            'config' => [
                'method' => 'POST',
                'url' => 'https://hooks.example.test/orders',
                'headers' => "Authorization: Bearer sk_live_SECRET\nX-Shop: mine",
                'body' => '{"number":"{{ order.number }}"}',
            ],
            'position' => 0,
        ]);

        return $rule->fresh(['conditions', 'actions']);
    }
}
