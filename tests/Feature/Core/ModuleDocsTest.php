<?php

namespace Tests\Feature\Core;

use App\Core\Modules\ModuleDocs;
use App\Core\Modules\ModuleManager;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * A module documents itself with Markdown files in its docs/ folder, one page
 * per side tab, translated by a `<locale>_` prefix. The reference module
 * torgash.hello ships two pages in two languages, which is what this pins.
 */
class ModuleDocsTest extends TestCase
{
    protected function docs(string $module = 'torgash.hello'): ModuleDocs
    {
        return new ModuleDocs(app(ModuleManager::class)->find($module));
    }

    public function test_pages_are_ordered_by_the_numeric_prefix_and_titled_by_the_first_heading(): void
    {
        $pages = $this->docs()->pages();

        $this->assertSame(['overview', 'anatomy', 'changelog'], array_column($pages, 'slug'));
        $this->assertSame('Overview', $pages[0]['title']);
        $this->assertSame('Anatomy of a module', $pages[1]['title']);
    }

    /**
     * A tab is labelled in the language its page is READ in — an English tab
     * over a Russian page is a screen that only looks half translated. The
     * ORDER stays the base file's, whatever a translation numbered itself.
     */
    public function test_a_tab_takes_its_title_from_the_translation_being_read(): void
    {
        $pages = $this->docs()->pages('ru');

        $this->assertSame(['overview', 'anatomy', 'changelog'], array_column($pages, 'slug'), 'the base file still orders the book');
        $this->assertSame('Обзор', $pages[0]['title']);

        // A language nobody translated into falls back to the base heading.
        $this->assertSame('Overview', $this->docs()->pages('de')[0]['title']);
    }

    public function test_a_page_renders_in_the_locale_and_falls_back_to_the_base_language(): void
    {
        $ru = $this->docs()->render('overview', 'ru');
        $this->assertStringContainsString('эталонный модуль', $ru);

        // No German translation exists, so the base English page is served.
        $this->assertStringContainsString('reference module', $this->docs()->render('overview', 'de'));

        $this->assertNull($this->docs()->render('no-such-page', 'en'));
    }

    public function test_raw_html_is_stripped_and_relative_images_point_at_the_media_route(): void
    {
        $html = $this->docs()->render('anatomy', 'en');

        $this->assertStringNotContainsString('<script', $html);
        // The relative src became the docs media route for this module.
        $this->assertStringContainsString('admin/modules/torgash.hello/docs/media/images/example.png', $html);
    }

    public function test_media_serves_an_image_but_refuses_traversal_and_non_images(): void
    {
        $docs = $this->docs();

        $this->assertNotNull($docs->media('images/example.png'));
        $this->assertNull($docs->media('../manifest.json'));
        $this->assertNull($docs->media('images/../../src/HelloServiceProvider.php'));
        $this->assertNull($docs->media('01-overview.md'));
    }

    public function test_the_docs_screen_renders_with_side_tabs_and_switches_pages(): void
    {
        $this->actingAs($this->admin());

        $this->get('http://localhost/admin/modules/torgash.hello/docs')
            ->assertOk()
            ->assertSee('Overview')
            ->assertSee('Anatomy of a module')
            ->assertSee('Documentation lives with the code');

        $this->get('http://localhost/admin/modules/torgash.hello/docs?doc=anatomy')
            ->assertOk()
            ->assertSee('Rules that do not bend');

        $this->get('http://localhost/admin/modules/torgash.hello/docs?doc=nope')->assertNotFound();
    }

    public function test_the_media_route_returns_the_image(): void
    {
        $this->actingAs($this->admin());

        $response = $this->get('http://localhost/admin/modules/torgash.hello/docs/media/images/example.png')->assertOk();
        $this->assertStringStartsWith('image/', (string) $response->headers->get('content-type'));

        $this->get('http://localhost/admin/modules/torgash.hello/docs/media/../manifest.json')
            ->assertNotFound();
    }

    public function test_a_module_without_a_docs_folder_has_none(): void
    {
        $this->assertFalse(app(ModuleManager::class)->find('torgash.cart')->hasDocs());
        $this->assertTrue(app(ModuleManager::class)->find('torgash.hello')->hasDocs());
    }

    protected function admin(): User
    {
        return User::firstWhere('email', 'module-docs@test') ?? tap(
            User::create(['name' => 'Admin', 'email' => 'module-docs@test', 'password' => bcrypt('x')]),
            fn ($u) => $u->assignRole(Role::findOrCreate('admin')),
        );
    }
}
