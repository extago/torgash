<?php

namespace Tests\Feature\Pages;

use App\Models\Storefront;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Torgash\Api\Models\ApiToken;
use Torgash\Pages\Models\Page;

/**
 * Static pages: the page itself, who it is for, and what happens to its
 * address when it is renamed.
 *
 * The address is the point of most of this. A privacy policy or a delivery
 * page is linked from printed material, from other people's sites and from the
 * shop's own menu, so "it 404s now" is the failure this module has to be
 * unable to produce by itself.
 */
class PageTest extends TestCase
{
    public function test_a_published_page_renders_its_title_and_expanded_body(): void
    {
        $this->page([
            'slug' => 'about',
            'title' => ['en' => 'About us'],
            // A placeholder, to prove the body goes through the same expansion
            // an HTML block's does rather than being printed raw.
            'body' => ['en' => '<p>Hello. Ring us: {callback_link}</p>'],
        ]);

        $response = $this->get('http://localhost/about')->assertOk();

        $response->assertSee('About us');
        $response->assertSee('Hello. Ring us:', false);
        $response->assertDontSee('{callback_link}');
    }

    /** A draft is not a page yet. */
    public function test_an_unpublished_page_is_not_reachable(): void
    {
        $this->page(['slug' => 'draft', 'enabled' => false]);

        $this->get('http://localhost/draft')->assertNotFound();
    }

    /**
     * Renaming leaves a 301. Anything else silently breaks every link anyone
     * ever made to the page, which is the one thing a static page cannot
     * afford.
     */
    public function test_renaming_a_page_redirects_the_old_address(): void
    {
        $page = $this->page(['slug' => 'shipping-info', 'title' => ['en' => 'Shipping']]);
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->put('http://localhost/admin/pages/'.$page->id, [
            'title' => 'Shipping',
            'slug' => 'delivery',
            'enabled' => '1',
        ])->assertRedirect();

        $this->assertSame('delivery', $page->fresh()->slug);
        $this->get('http://localhost/shipping-info')->assertRedirect('/delivery');
        $this->get('http://localhost/delivery')->assertOk();
    }

    /**
     * Display rules, the same engine a block and a promotion use. A page
     * scoped to another storefront 404s rather than rendering: a page
     * describing something this visitor cannot have is worse than no page.
     */
    public function test_a_page_scoped_to_another_storefront_is_not_reachable_here(): void
    {
        $other = Storefront::create([
            'code' => 'wholesale', 'name' => 'Wholesale front', 'domains' => ['wholesale.test'],
            'default_locale' => 'en', 'default_currency' => 'USD', 'enabled' => true,
        ]);

        $this->page([
            'slug' => 'wholesale-terms',
            'rules' => [['kind' => 'storefront', 'operator' => 'in', 'value' => [$other->id]]],
            'rules_match' => 'all',
        ]);

        $this->get('http://localhost/wholesale-terms')->assertNotFound();
    }

    /** Published, in the sitemap, and only then. */
    public function test_the_sitemap_lists_pages_that_asked_to_be_in_it(): void
    {
        $this->page(['slug' => 'mapped-page']);
        $this->page(['slug' => 'unmapped-page', 'in_sitemap' => false]);
        $this->page(['slug' => 'draft-page', 'enabled' => false]);

        $response = $this->get('http://localhost/sitemap.xml')->assertOk();

        $response->assertSee('/mapped-page', false);
        $response->assertDontSee('/unmapped-page', false);
        $response->assertDontSee('/draft-page', false);
    }

    /** The admin makes an address out of the title, and never collides. */
    public function test_the_admin_derives_a_unique_address(): void
    {
        $admin = User::role('admin')->firstOrFail();

        foreach ([1, 2] as $ignored) {
            $this->actingAs($admin)->post('http://localhost/admin/pages', [
                'title' => 'Privacy policy',
                'enabled' => '1',
            ])->assertRedirect();
        }

        $slugs = Page::orderBy('id')->pluck('slug')->all();

        $this->assertContains('privacy-policy', $slugs);
        $this->assertContains('privacy-policy-2', $slugs);
    }

    /**
     * A blank field in one language does not wipe the other. This is the trap
     * every translatable form here has to survive: a manager saving the page
     * with the content switcher on RU must not empty the English text.
     */
    public function test_saving_in_one_language_leaves_the_other_alone(): void
    {
        $page = $this->page([
            'slug' => 'bilingual',
            'title' => ['en' => 'Delivery', 'ru' => 'Доставка'],
            'body' => ['en' => '<p>English body</p>', 'ru' => '<p>Русский текст</p>'],
        ]);

        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->put('http://localhost/admin/pages/'.$page->id, [
            'title' => 'Delivery and payment',
            'slug' => 'bilingual',
            'body' => '<p>English body, edited</p>',
            'enabled' => '1',
        ])->assertRedirect();

        $fresh = $page->fresh();
        $this->assertSame('Delivery and payment', $fresh->t('title', 'en'));
        $this->assertSame('Доставка', $fresh->t('title', 'ru'), 'the other language must survive an edit made in this one');
        $this->assertSame('<p>Русский текст</p>', $fresh->t('body', 'ru'));
    }

    /** The API reads a page by its address and switches it on and off. */
    public function test_the_api_reads_a_page_by_slug_and_publishes_it(): void
    {
        $this->page(['slug' => 'api-page', 'title' => ['en' => 'Api page'], 'enabled' => false]);

        [, $token] = ApiToken::issue(User::role('admin')->firstOrFail(), 'Tests');
        $headers = ['Authorization' => 'Bearer '.$token];

        $read = $this->json('GET', 'http://localhost/api/v2/admin/pages/api-page', [], $headers)->assertOk();
        $read->assertJsonPath('data.slug', 'api-page');
        $read->assertJsonPath('data.enabled', false);

        $this->json('PATCH', 'http://localhost/api/v2/admin/pages/api-page', ['enabled' => true], $headers)->assertOk();

        $this->assertTrue(Page::where('slug', 'api-page')->value('enabled'));
    }

    /** Uninstalling leaves nothing behind — the module rule, checked here too. */
    public function test_the_table_is_the_module_prefixed_one(): void
    {
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('m_pages_pages'));
    }

    /**
     * The address is in the ROOT, and it is a fallback route — so a page can
     * only ever take an address nothing else claimed. This is the test that
     * says so: the catalogue keeps its own, and an unknown address is still a
     * plain 404 rather than an empty page.
     */
    public function test_a_page_never_takes_an_address_another_route_owns(): void
    {
        // A page whose slug is exactly the catalogue's address.
        $this->page(['slug' => 'catalog', 'title' => ['en' => 'Not the catalogue']]);

        $this->get('http://localhost/catalog')->assertOk()->assertDontSee('Not the catalogue');
        $this->get('http://localhost/no-such-address-at-all')->assertNotFound();
    }

    /** A section: the page sits under its parent, at the parent's address plus its own. */
    public function test_a_child_page_lives_under_its_parent(): void
    {
        $section = $this->page(['slug' => 'info', 'title' => ['en' => 'Information centre']]);
        $child = $this->page(['slug' => 'warranty', 'title' => ['en' => 'Warranty'], 'parent_id' => $section->id]);

        $this->assertSame('info/warranty', $child->fresh()->path);

        $response = $this->get('http://localhost/info/warranty')->assertOk();
        // Breadcrumbs, and the section navigation beside the text.
        $response->assertSee('Information centre');
        $response->assertSee('/info', false);
    }

    /**
     * Renaming a section rewrites every address under it — and leaves a 301 on
     * each. This is the whole reason `path` is a stored column.
     */
    public function test_renaming_a_section_moves_its_pages_and_redirects_them(): void
    {
        $section = $this->page(['slug' => 'info', 'title' => ['en' => 'Info']]);
        $child = $this->page(['slug' => 'delivery', 'parent_id' => $section->id]);
        $grandchild = $this->page(['slug' => 'abroad', 'parent_id' => $child->id]);

        $this->assertSame('info/delivery/abroad', $grandchild->fresh()->path);

        $section->slug = 'help';
        $section->save();

        $this->assertSame('help/delivery', $child->fresh()->path);
        $this->assertSame('help/delivery/abroad', $grandchild->fresh()->path);

        $this->get('http://localhost/info')->assertRedirect('/help');
        $this->get('http://localhost/info/delivery')->assertRedirect('/help/delivery');
        $this->get('http://localhost/info/delivery/abroad')->assertRedirect('/help/delivery/abroad');
    }

    /** Moving a page into a section is the same act, and redirects the same way. */
    public function test_moving_a_page_into_a_section_redirects_its_old_address(): void
    {
        $section = $this->page(['slug' => 'info']);
        $page = $this->page(['slug' => 'returns']);

        $page->parent_id = $section->id;
        $page->save();

        $this->assertSame('info/returns', $page->fresh()->path);
        $this->get('http://localhost/returns')->assertRedirect('/info/returns');
    }

    /** A deleted section does not take its children with it — they move up. */
    public function test_deleting_a_section_lifts_its_children(): void
    {
        $section = $this->page(['slug' => 'info']);
        $child = $this->page(['slug' => 'terms', 'parent_id' => $section->id]);

        $section->delete();

        $child = $child->fresh();
        $this->assertNull($child->parent_id);
        $this->assertSame('terms', $child->path);
        $this->get('http://localhost/info/terms')->assertRedirect('/terms');
    }

    /** A page cannot be put inside itself; the request must not spin. */
    public function test_a_cycle_is_refused(): void
    {
        $section = $this->page(['slug' => 'info']);
        $child = $this->page(['slug' => 'delivery', 'parent_id' => $section->id]);

        $this->expectException(\RuntimeException::class);

        $section->parent_id = $child->id;
        $section->save();
    }

    /** Two sections may each have a page called the same thing. */
    public function test_siblings_are_unique_but_cousins_need_not_be(): void
    {
        $first = $this->page(['slug' => 'info']);
        $second = $this->page(['slug' => 'help']);

        $this->page(['slug' => 'delivery', 'parent_id' => $first->id]);
        $this->page(['slug' => 'delivery', 'parent_id' => $second->id]);

        $this->get('http://localhost/info/delivery')->assertOk();
        $this->get('http://localhost/help/delivery')->assertOk();
    }

    /** The address pages used to have keeps working, as a redirect to the real one. */
    public function test_the_old_page_prefix_redirects_to_the_real_address(): void
    {
        $section = $this->page(['slug' => 'info']);
        $this->page(['slug' => 'delivery', 'parent_id' => $section->id]);

        $this->get('http://localhost/page/delivery')->assertRedirect('/info/delivery');
    }

    /**
     * The same page as text, at the same address plus .md — the convention the
     * catalogue's product pages follow.
     *
     * Worth having for pages above all: what an agent is asked about a shop is
     * delivery, returns and who to call, and none of that is in the API.
     */
    public function test_a_page_has_a_text_version_that_lists_its_section(): void
    {
        $root = $this->page([
            'slug' => 'help', 'title' => ['en' => 'Help'],
            'body' => ['en' => '<p>Ask us anything.</p><ul><li>By phone</li></ul>'],
        ]);

        $this->page(['slug' => 'returns', 'title' => ['en' => 'Returns'], 'parent_id' => $root->id]);
        $this->page(['slug' => 'draft-child', 'title' => ['en' => 'Draft'], 'parent_id' => $root->id, 'enabled' => false]);

        $body = $this->get('http://localhost/help.md')->assertOk()->getContent();

        $this->assertStringContainsString('# Help', $body);
        $this->assertStringContainsString('Ask us anything.', $body);
        $this->assertStringContainsString('- By phone', $body);
        $this->assertStringContainsString('/help/returns.md', $body);
        $this->assertStringNotContainsString('draft-child', $body, 'an unpublished page is not a page yet, in either form');
    }

    /** The rename table is keyed by the real address; the suffix goes back on afterwards. */
    public function test_renaming_redirects_the_text_address_too(): void
    {
        $page = $this->page(['slug' => 'old-terms', 'title' => ['en' => 'Terms']]);
        $admin = User::role('admin')->firstOrFail();

        $this->actingAs($admin)->put('http://localhost/admin/pages/'.$page->id, [
            'title' => 'Terms', 'slug' => 'terms', 'enabled' => '1',
        ])->assertRedirect();

        $this->get('http://localhost/old-terms.md')->assertRedirect('/terms.md');
    }

    protected function page(array $attributes): Page
    {
        return Page::create([
            'title' => ['en' => 'A page'],
            'body' => ['en' => '<p>Body</p>'],
            'enabled' => true,
            'in_sitemap' => true,
            ...$attributes,
        ]);
    }
}
