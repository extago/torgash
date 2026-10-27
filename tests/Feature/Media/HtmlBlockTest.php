<?php

namespace Tests\Feature\Media;

use App\Models\User;
use Tests\TestCase;
use Torgash\Editor\Toolbar;
use Torgash\Pages\Models\Page;

/**
 * The HTML block — raw markup the visual editor keeps verbatim. Its behaviour
 * lives in the browser (htmlblock.mjs, checked by hand in Chrome: a styled
 * <div> and a table pasted into the source survive a visual edit); what the
 * server owns is that the button is offered with its words, and that a saved
 * block reaches the storefront as the markup that was typed.
 */
class HtmlBlockTest extends TestCase
{
    public function test_the_toolbar_offers_it_with_its_own_words(): void
    {
        $this->assertSame('file-code', collect(Toolbar::parse('bold, html'))->firstWhere('key', 'html')['icon']);

        $page = Page::create(['title' => ['en' => 'Promo'], 'slug' => 'promo-block', 'body' => ['en' => '<p>x</p>'], 'enabled' => true, 'in_sitemap' => true]);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/pages/'.$page->id.'/edit')
            ->assertOk()
            ->assertSee('data-tiptap-command="html"', false)
            ->assertSee('data-tiptap-html-labels', false);
    }

    public function test_a_saved_block_reaches_the_page_as_typed(): void
    {
        $markup = '<div data-html-block=""><div class="promo" style="padding:12px;background:#fde68a"><strong>Sale</strong> today</div></div>';
        $page = Page::create(['title' => ['en' => 'Promo'], 'slug' => 'promo-shown', 'body' => ['en' => '<p>Intro</p>'.$markup], 'enabled' => true, 'in_sitemap' => true]);

        $this->get('http://localhost/'.$page->path)->assertOk()
            ->assertSee('style="padding:12px;background:#fde68a"', false)
            ->assertSee('class="promo"', false);
    }
}
