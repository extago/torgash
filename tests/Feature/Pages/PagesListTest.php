<?php

namespace Tests\Feature\Pages;

use App\Models\User;
use Tests\TestCase;
use Torgash\Pages\Models\Page;

/**
 * Pages in the admin's common shape: search over every language and the
 * address, published/draft tabs, the status in place and for several at once
 * — a bulk delete of a section and its child going through the models, so the
 * child still lands where one delete puts it — and a form that stays open.
 */
class PagesListTest extends TestCase
{
    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::role('admin')->firstOrFail();
    }

    protected function page(array $attributes): Page
    {
        return Page::create(['title' => ['en' => 'A page'], 'body' => ['en' => '<p>Body</p>'], 'enabled' => true, 'in_sitemap' => true, ...$attributes]);
    }

    public function test_search_and_tabs(): void
    {
        $this->page(['title' => ['en' => 'Quokka delivery', 'ru' => 'Доставка квокка'], 'slug' => 'quokka-delivery']);
        $this->page(['title' => ['en' => 'Quokka draft'], 'slug' => 'quokka-draft', 'enabled' => false]);

        $this->actingAs($this->admin)->get('http://localhost/admin/pages?q=квокка')->assertOk()
            ->assertSee('Quokka delivery')->assertDontSee('Quokka draft');
        $this->get('http://localhost/admin/pages?q=quokka&status=draft')->assertOk()
            ->assertSee('Quokka draft')->assertDontSee('Quokka delivery');
    }

    public function test_status_in_place_and_bulk(): void
    {
        $section = $this->page(['slug' => 'bulk-info']);
        $child = $this->page(['slug' => 'bulk-terms', 'parent_id' => $section->id]);
        $other = $this->page(['slug' => 'bulk-other']);

        $this->actingAs($this->admin)->patch("http://localhost/admin/pages/{$other->id}/status", ['enabled' => 0])
            ->assertOk()->assertSee('page-status-'.$other->id, false);
        $this->assertFalse($other->fresh()->enabled);

        $this->post('http://localhost/admin/pages/bulk', ['action' => 'publish', 'ids' => [$other->id]])->assertRedirect();
        $this->assertTrue($other->fresh()->enabled);

        // The section goes; its child is lifted, not deleted with it.
        $this->post('http://localhost/admin/pages/bulk', ['action' => 'delete', 'ids' => [$section->id]])->assertRedirect();
        $this->assertNull(Page::find($section->id));
        $this->assertSame('bulk-terms', $child->fresh()->path);
    }

    public function test_the_form_stays_open_and_can_open_a_child(): void
    {
        $page = $this->page(['slug' => 'stay-here']);

        $this->actingAs($this->admin)->get("http://localhost/admin/pages/{$page->id}/edit")->assertOk()
            ->assertSee('page-delete', false);
        $this->get("http://localhost/admin/pages/create?parent={$page->id}")->assertOk()
            ->assertSee('value="'.$page->id.'" selected', false);

        $this->put("http://localhost/admin/pages/{$page->id}", ['title' => 'A page', 'slug' => 'stay-here', 'enabled' => 1])
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.pages.edit', $page));
        $this->put("http://localhost/admin/pages/{$page->id}", ['title' => 'A page', 'slug' => 'stay-here', 'after_save' => 'close'])
            ->assertRedirect(route('admin.pages.index'));
    }
}
