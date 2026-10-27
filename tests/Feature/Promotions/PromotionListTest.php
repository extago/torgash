<?php

namespace Tests\Feature\Promotions;

use App\Models\User;
use Tests\TestCase;
use Torgash\Promotions\Models\Promotion;

/**
 * The promotions list: tabs by the COMPUTED state, search, zone, the switch in
 * place, several at once, and a copy that arrives switched off.
 */
class PromotionListTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    protected function promotion(array $attributes = []): Promotion
    {
        $promotion = Promotion::create($attributes + ['name' => ['en' => 'Sale'], 'zone' => 'catalog', 'combine' => 'replace', 'enabled' => true]);
        $promotion->tiers()->create([
            'position' => 0,
            'conditions' => ['match' => 'all', 'rules' => []],
            'discount_type' => 'percent', 'discount_value' => 10,
        ]);

        return $promotion;
    }

    public function test_state_tabs_search_and_zone(): void
    {
        $this->promotion(['name' => ['en' => 'Zebra running']]);
        $this->promotion(['name' => ['en' => 'Zebra later'], 'starts_at' => now()->addDay()]);
        $this->promotion(['name' => ['en' => 'Zebra cart'], 'zone' => 'cart', 'enabled' => false]);

        $this->actingAs($this->admin)
            ->get('http://localhost/admin/promotions?q=zebra&state=scheduled')
            ->assertOk()->assertSee('Zebra later')->assertDontSee('Zebra running');

        $this->get('http://localhost/admin/promotions?q=zebra&zone=cart')
            ->assertOk()->assertSee('Zebra cart')->assertDontSee('Zebra later');
    }

    public function test_the_switch_in_place_and_in_bulk(): void
    {
        $a = $this->promotion();
        $b = $this->promotion();

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/promotions/{$a->id}/status", ['enabled' => 0])
            ->assertOk()->assertSee('promotion-status-'.$a->id, false);
        $this->assertFalse($a->refresh()->enabled);

        $this->post('http://localhost/admin/promotions/bulk', ['action' => 'disable', 'ids' => [$b->id]]);
        $this->assertFalse($b->refresh()->enabled);

        $this->post('http://localhost/admin/promotions/bulk', ['action' => 'delete', 'ids' => [$a->id, $b->id]]);
        $this->assertNull($a->fresh());
    }

    public function test_a_copy_arrives_switched_off_without_a_page_and_with_its_tiers(): void
    {
        $original = $this->promotion(['slug' => 'black-friday', 'published' => true, 'used_count' => 7]);

        $this->actingAs($this->admin)
            ->post("http://localhost/admin/promotions/{$original->id}/duplicate")
            ->assertRedirect();

        $copy = Promotion::where('id', '!=', $original->id)->latest('id')->firstOrFail();
        $this->assertFalse($copy->enabled);
        $this->assertFalse($copy->published);
        $this->assertNull($copy->slug);
        $this->assertSame(0, (int) $copy->used_count);
        $this->assertCount(1, $copy->tiers);
        $this->assertStringContainsString('Sale', $copy->t('name', 'en'));
    }

    public function test_saving_stays_on_the_form_and_save_and_close_leaves_it(): void
    {
        $promotion = $this->promotion();
        $form = ['name' => 'Sale', 'zone' => 'catalog', 'combine' => 'replace', 'enabled' => 1,
            'tiers' => [['match' => 'all', 'discount_type' => 'percent', 'discount_value' => 10]]];

        $this->actingAs($this->admin)
            ->put("http://localhost/admin/promotions/{$promotion->id}", $form)
            ->assertRedirect(route('admin.promotions.edit', $promotion));

        $this->put("http://localhost/admin/promotions/{$promotion->id}", $form + ['after_save' => 'close'])
            ->assertRedirect(route('admin.promotions.index'));
    }
}
