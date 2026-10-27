<?php

namespace Tests\Feature\Motivation;

use App\Models\User;
use Tests\TestCase;
use Torgash\Motivation\Models\MotivationItem;

/**
 * The info-block list in the admin's common shape: a search and tabs that
 * narrow it, the status switched in place, several cards at once, and a form
 * that stays open after Save.
 */
class MotivationListTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    protected function card(string $name, bool $enabled = true): MotivationItem
    {
        return MotivationItem::create([
            'name' => ['en' => $name], 'sort' => 0, 'enabled' => $enabled, 'expanded' => true,
            'icon_type' => 'none', 'content_type' => 'html', 'content' => ['en' => 'Text'],
        ]);
    }

    public function test_search_and_tabs_narrow_the_list(): void
    {
        $this->card('Delivery Omega');
        $this->card('Warranty Omega', false);

        $this->actingAs($this->admin)->get('http://localhost/admin/motivation?q=delivery%20omega')
            ->assertOk()->assertSee('Delivery Omega')->assertDontSee('Warranty Omega');
        $this->get('http://localhost/admin/motivation?state=off&q=omega')
            ->assertSee('Warranty Omega')->assertDontSee('Delivery Omega');
    }

    public function test_status_in_place_and_in_bulk(): void
    {
        $one = $this->card('Card one');
        $two = $this->card('Card two');

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/motivation/{$one->id}/status", ['enabled' => 0], ['HX-Request' => 'true'])
            ->assertOk()->assertSee('motivation-status-'.$one->id, false);
        $this->assertFalse($one->fresh()->enabled);

        $this->post('http://localhost/admin/motivation/bulk', ['action' => 'delete', 'ids' => [$one->id, $two->id]])->assertRedirect();
        $this->assertNull(MotivationItem::find($two->id));
    }

    public function test_save_stays_on_the_form_and_close_goes_back(): void
    {
        $card = $this->card('Payment');
        $fields = ['name' => 'Payment', 'sort' => 5, 'icon_type' => 'none', 'content_type' => 'html', 'content' => 'Cards and cash', 'enabled' => 1];

        $this->actingAs($this->admin)->put("http://localhost/admin/motivation/{$card->id}", $fields)
            ->assertRedirect(route('admin.motivation.edit', $card));
        $this->put("http://localhost/admin/motivation/{$card->id}", $fields + ['after_save' => 'close'])
            ->assertRedirect(route('admin.motivation.index'));
        $this->get("http://localhost/admin/motivation/{$card->id}/edit")->assertOk()->assertSee('motivation-delete', false);
    }
}
