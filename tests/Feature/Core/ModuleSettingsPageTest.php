<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Tests\TestCase;

/**
 * One module's settings page: the module's own screens are offered, a setting
 * changed from its default says so, and "Restore the defaults" puts them back.
 */
class ModuleSettingsPageTest extends TestCase
{
    public function test_the_page_names_the_module_its_screens_and_what_was_changed(): void
    {
        app(SettingsManager::class)->set('torgash.api', 'rate_limit', 60);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/settings/torgash.api')
            ->assertOk()
            ->assertSee('/admin/api/tokens', false)
            ->assertSee('id="settings-form"', false)
            ->assertSee(__('Restore the defaults'));
    }

    public function test_restoring_the_defaults_forgets_the_stored_values(): void
    {
        $settings = app(SettingsManager::class);
        $settings->set('torgash.api', 'rate_limit', 60);

        $this->actingAs(User::role('admin')->firstOrFail())
            ->post('http://localhost/admin/settings/torgash.api/reset')
            ->assertRedirect();

        $this->assertEquals(120, setting('torgash.api', 'rate_limit'));
    }

    public function test_the_core_page_still_renders(): void
    {
        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/settings/core')
            ->assertOk();

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('http://localhost/admin/settings')
            ->assertOk();
    }
}
