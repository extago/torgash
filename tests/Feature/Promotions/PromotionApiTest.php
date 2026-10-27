<?php

namespace Tests\Feature\Promotions;

use App\Models\User;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Promotions\Models\Promotion;

/**
 * Sales over the API: read whole, switched on from outside, and firmly unable
 * to have their tiers rewritten by a PATCH — the conversions a tier's value
 * needs (percent, minor units, target price) live in the admin controller, and
 * a second spelling of them here is the second source of truth this codebase
 * keeps refusing to grow.
 */
class PromotionApiTest extends TestCase
{
    protected function call_(string $method, string $uri, array $data = [])
    {
        [, $token] = ApiToken::issue(User::role('admin')->firstOrFail(), 'Tests');

        return $this->json($method, 'http://localhost'.$uri, $data, [
            'Authorization' => 'Bearer '.$token,
        ]);
    }

    public function test_a_promotion_is_read_with_its_tiers_in_words(): void
    {
        $promotion = $this->promotion(['name' => ['en' => 'Everything at 999'], 'zone' => 'catalog']);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'to_amount', 'discount_value' => 999_00,
        ]);

        $response = $this->call_('GET', '/api/v2/admin/promotions/'.$promotion->id)->assertOk();

        $response->assertJsonPath('data.zone', 'catalog');
        $response->assertJsonPath('data.status', 'running');
        $response->assertJsonPath('data.tiers.0.discount_type', 'to_amount');
        $response->assertJsonPath('data.tiers.0.discount_value', 999_00);
        $this->assertNotEmpty($response->json('data.tiers.0.discount'), 'the tier explains itself in words');
        $this->assertNotEmpty($response->json('data.tiers.0.conditions'));
    }

    /** The four states are one question, and a caller asks it in one word. */
    public function test_the_status_filter_answers_the_switch_and_the_calendar_together(): void
    {
        $this->promotion(['name' => ['en' => 'Running'], 'zone' => 'cart']);
        $this->promotion(['name' => ['en' => 'Later'], 'zone' => 'cart', 'starts_at' => now()->addWeek()]);
        $this->promotion(['name' => ['en' => 'Over'], 'zone' => 'cart', 'ends_at' => now()->subWeek()]);
        $this->promotion(['name' => ['en' => 'Switched off'], 'zone' => 'cart', 'enabled' => false]);

        foreach (['running' => 'Running', 'scheduled' => 'Later', 'ended' => 'Over', 'off' => 'Switched off'] as $status => $name) {
            $response = $this->call_('GET', '/api/v2/admin/promotions?status='.$status)->assertOk();

            $this->assertCount(1, $response->json('data'), "one promotion is {$status}");
            $this->assertSame($name, $response->json('data.0.name.en'));
        }
    }

    /** An unknown word must not quietly mean "everything". */
    public function test_an_unknown_status_matches_nothing(): void
    {
        $this->promotion(['name' => ['en' => 'Running'], 'zone' => 'cart']);

        $response = $this->call_('GET', '/api/v2/admin/promotions?status=whenever')->assertOk();

        $this->assertCount(0, $response->json('data'));
    }

    /** The reason to reach for this API at all: switch the sale on at midnight. */
    public function test_a_patch_switches_a_sale_on_and_schedules_it(): void
    {
        $promotion = $this->promotion(['name' => ['en' => 'Black Friday'], 'zone' => 'cart', 'enabled' => false]);

        $this->call_('PATCH', '/api/v2/admin/promotions/'.$promotion->id, [
            'enabled' => true,
            'starts_at' => now()->addDay()->toIso8601String(),
            'usage_limit' => 500,
        ])->assertOk();

        $promotion->refresh();

        $this->assertTrue($promotion->enabled);
        $this->assertSame(500, (int) $promotion->usage_limit);
        $this->assertSame('scheduled', $promotion->status());
    }

    public function test_a_patch_cannot_rewrite_the_tiers_or_the_zone(): void
    {
        $promotion = $this->promotion(['name' => ['en' => 'Fixed shape'], 'zone' => 'cart']);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        $this->call_('PATCH', '/api/v2/admin/promotions/'.$promotion->id, [
            'zone' => 'catalog',
            'combine' => 'stack_multiply',
            'tiers' => [['discount_type' => 'percent', 'discount_value' => 90]],
        ])->assertOk();

        $promotion->refresh();

        $this->assertSame('cart', $promotion->zone);
        $this->assertSame('replace', $promotion->combine);
        $this->assertCount(1, $promotion->tiers);
        $this->assertSame(10, (int) $promotion->tiers->first()->discount_value);
    }

    public function test_the_resource_needs_the_promotions_permission(): void
    {
        $customer = User::create(['name' => 'Nobody', 'email' => 'nobody@example.test', 'password' => 'secret']);
        [, $token] = ApiToken::issue($customer, 'Tests');

        $this->json('GET', 'http://localhost/api/v2/admin/promotions', [], [
            'Authorization' => 'Bearer '.$token,
        ])->assertForbidden();
    }

    /** The description is written FROM the registry, so the page must know it. */
    public function test_the_openapi_document_describes_promotions(): void
    {
        $document = $this->get('http://localhost/api/v2/openapi.json')->assertOk()->json();

        $this->assertArrayHasKey('/api/v2/admin/promotions', $document['paths']);
        $this->assertArrayHasKey('/api/v2/admin/promotions/{key}', $document['paths']);

        // Written FROM the registry: the PATCH exists because the resource
        // declared an `update`, and the fields it lists are the `writable` ones.
        $patch = $document['paths']['/api/v2/admin/promotions/{key}']['patch'];
        $this->assertNotEmpty($patch);
        $this->assertStringContainsString('promotions', json_encode($patch));
    }

    protected function promotion(array $attributes): Promotion
    {
        return Promotion::create($attributes + [
            'combine' => 'replace',
            'enabled' => true,
        ]);
    }
}
