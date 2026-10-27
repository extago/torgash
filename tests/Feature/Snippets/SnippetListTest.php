<?php

namespace Tests\Feature\Snippets;

use App\Models\User;
use Tests\TestCase;
use Torgash\Snippets\Models\Snippet;

/**
 * The snippets list in the admin's common shape: search that also reads the
 * code, tabs by place on the page, the status switched in place, several at
 * once, a copy that arrives switched off, and a form that stays open on Save.
 */
class SnippetListTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    protected function snippet(string $name, string $placement = 'head', string $code = '<script></script>'): Snippet
    {
        return Snippet::create(['name' => $name, 'placement' => $placement, 'code' => $code, 'enabled' => true, 'position' => 0, 'rules_match' => 'all']);
    }

    public function test_search_reads_the_code_and_tabs_narrow_by_place(): void
    {
        $this->snippet('Counter Alpha', 'head', "<script>gtag('config', 'G-ZZTOP')</script>");
        $this->snippet('Chat Beta', 'footer');

        $this->actingAs($this->admin)->get('http://localhost/admin/snippets?q=ZZTOP')
            ->assertOk()->assertSee('Counter Alpha')->assertDontSee('Chat Beta');
        $this->get('http://localhost/admin/snippets?place=footer')
            ->assertSee('Chat Beta')->assertDontSee('Counter Alpha');
    }

    public function test_status_in_place_bulk_and_a_copy_that_is_off(): void
    {
        $one = $this->snippet('Pixel one');
        $two = $this->snippet('Pixel two');

        $this->actingAs($this->admin)
            ->patch("http://localhost/admin/snippets/{$one->id}/status", ['enabled' => 0], ['HX-Request' => 'true'])
            ->assertOk()->assertSee('snippet-status-'.$one->id, false);
        $this->assertFalse($one->fresh()->enabled);

        $this->post("http://localhost/admin/snippets/{$two->id}/duplicate")->assertRedirect();
        $copy = Snippet::where('id', '>', $two->id)->latest('id')->firstOrFail();
        $this->assertFalse($copy->enabled);
        $this->assertSame($two->code, $copy->code);

        $this->post('http://localhost/admin/snippets/bulk', ['action' => 'delete', 'ids' => [$two->id, $copy->id]])->assertRedirect();
        $this->assertNull(Snippet::find($two->id));
    }

    public function test_save_stays_on_the_form_and_close_goes_back(): void
    {
        $snippet = $this->snippet('Widget');
        $fields = ['name' => 'Widget', 'placement' => 'footer', 'code' => '<script></script>', 'enabled' => 1];

        $this->actingAs($this->admin)->put("http://localhost/admin/snippets/{$snippet->id}", $fields)
            ->assertRedirect(route('admin.snippets.edit', $snippet));
        $this->put("http://localhost/admin/snippets/{$snippet->id}", $fields + ['after_save' => 'close'])
            ->assertRedirect(route('admin.snippets.index'));
        $this->get("http://localhost/admin/snippets/{$snippet->id}/edit")->assertOk()->assertSee('snippet-delete', false);
    }
}
