<?php

namespace Tests\Feature\Core;

use App\Core\Settings\SettingsManager;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The icon in the browser's tab of an admin page: the engine's own mark until
 * the shop uploads one on Settings → Admin panel — an `image` setting, the
 * first of its type, whose file lives on the public disk and whose value is
 * the file's address.
 */
class AdminIconTest extends TestCase
{
    public function test_the_admin_and_the_sign_in_page_carry_the_engines_mark_by_default(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('rel="icon"', false)->assertSee('/assets/admin/favicon.svg');

        $this->actingAs(User::role('admin')->firstOrFail())
            ->get('/admin')->assertOk()->assertSee('/assets/admin/favicon.svg');
    }

    public function test_an_uploaded_svg_becomes_the_icon_and_the_tick_takes_it_away(): void
    {
        Storage::fake('public');
        $admin = User::role('admin')->firstOrFail();
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 8 8"><rect width="8" height="8" fill="#f00"/></svg>';

        $this->actingAs($admin)
            ->post(route('admin.settings.update', 'core'), $this->values() + [
                'admin_icon' => UploadedFile::fake()->createWithContent('mark.svg', $svg),
            ])
            ->assertSessionHasNoErrors();

        $url = (string) app(SettingsManager::class)->get('core', 'admin_icon');
        $this->assertStringContainsString('/settings/admin_icon-', $url);
        $this->assertStringEndsWith('.svg', $url);
        Storage::disk('public')->assertExists('settings/'.basename($url));

        $this->get('/admin')->assertSee('rel="icon" href="'.$url.'"', false);

        $this->post(route('admin.settings.update', 'core'), $this->values() + ['admin_icon__remove' => '1'])
            ->assertSessionHasNoErrors();

        $this->assertSame('', (string) app(SettingsManager::class)->get('core', 'admin_icon', ''));
        Storage::disk('public')->assertMissing('settings/'.basename($url));
        $this->get('/admin')->assertSee('/assets/admin/favicon.svg');
    }

    public function test_a_file_that_is_not_a_picture_is_refused(): void
    {
        Storage::fake('public');

        $this->actingAs(User::role('admin')->firstOrFail())
            ->from('/admin/settings')
            ->post(route('admin.settings.update', 'core'), $this->values() + [
                'admin_icon' => UploadedFile::fake()->create('evil.php', 1, 'text/x-php'),
            ])
            ->assertSessionHasErrors('admin_icon');

        $this->assertSame('', (string) app(SettingsManager::class)->get('core', 'admin_icon', ''));
    }

    /** The core form always carries every stored setting; here it is what the screen would resend. */
    protected function values(): array
    {
        $values = [];
        foreach (\App\Core\Settings\CoreSettings::definitions() as $def) {
            if (! isset($def['key']) || in_array($def['type'] ?? 'text', ['group', 'action', 'link', 'image'], true)) {
                continue;
            }
            $values[$def['key']] = app(SettingsManager::class)->get('core', $def['key'], $def['default'] ?? null);
        }

        return $values;
    }
}
