<?php

namespace Tests\Feature\Core;

use App\Core\Modules\ModuleManager;
use App\Core\Themes\ThemeManager;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * A theme must be able to replace a module's template without the module
 * knowing, and without either of them being edited. That is the whole promise
 * of "modules never patch each other".
 */
class ThemeCascadeTest extends TestCase
{
    public function test_a_theme_view_wins_over_the_module_view(): void
    {
        $rendered = View::make('torgash.hello::hello', ['greeting' => 'hi'])->render();

        $this->assertStringContainsString('theme override works', $rendered);
        $this->assertStringNotContainsString('module fallback view', $rendered);
    }

    public function test_the_module_view_is_used_when_the_theme_does_not_override_it(): void
    {
        // A theme that exists but overrides nothing: the module's own file has
        // to answer, or a theme would have to copy every template it does not
        // want to change.
        $themes = app(ThemeManager::class);
        $themes->setActive('nonexistent-theme');
        $themes->registerModuleViews(app(ModuleManager::class)->find('torgash.hello'));

        $rendered = View::make('torgash.hello::hello', ['greeting' => 'hi'])->render();

        $this->assertStringContainsString('module fallback view', $rendered);
    }

    /**
     * Through a REAL request: the storefront's theme is only known once
     * ResolveStorefront has read it, long after boot wired the cascade from the
     * configured default. Until setActive() re-laid the cascade, picking a
     * theme on a storefront changed nothing, and every test above still passed
     * — they asked the pieces, never the page. Uses the Crystal test theme (a
     * child of `default` whose only difference is its defaults: the light
     * header among them).
     */
    public function test_the_storefronts_theme_draws_the_page(): void
    {
        $storefront = \App\Models\Storefront::query()->firstOrFail();

        $storefront->update(['theme' => 'crystal']);
        $crystal = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('torgash-header-icons', $crystal, 'the theme\'s own answer: the light header');

        $storefront->update(['theme' => 'default']);
        $default = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringNotContainsString('torgash-header-icons', $default, 'and back again, in the same process');
    }

    public function test_a_storefront_can_only_be_given_a_theme_that_exists(): void
    {
        $admin = \App\Models\User::factory()->create();
        $admin->assignRole('admin');
        $storefront = \App\Models\Storefront::query()->firstOrFail();

        $this->actingAs($admin)->get(route('admin.storefronts.edit', $storefront))->assertOk()
            ->assertSee('<option value="crystal"', false);

        $this->actingAs($admin)->put(route('admin.storefronts.update', $storefront), ['theme' => 'no-such-theme'])
            ->assertSessionHasErrors('theme');
    }

    public function test_the_theme_chain_starts_at_the_active_theme(): void
    {
        $themes = app(ThemeManager::class);
        $themes->setActive('default');

        $this->assertSame('default', $themes->chain()[0]);
    }
}
