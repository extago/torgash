<?php

namespace Tests\Feature\Core;

use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Settings, currencies, languages, redirects and background tasks each need a
 * permission of their own: signing in to the admin is not enough, and neither
 * the sidebar nor the search offers a screen the reader cannot open.
 */
class SystemScreenPermissionsTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function screens(): array
    {
        return [
            'settings' => ['/admin/settings', 'settings.manage'],
            'one module' => ['/admin/settings/torgash.api', 'settings.manage'],
            'currencies' => ['/admin/currencies', 'currencies.manage'],
            'languages' => ['/admin/languages', 'languages.manage'],
            'redirects' => ['/admin/redirects', 'redirects.manage'],
            'tasks' => ['/admin/tasks', 'tasks.manage'],
        ];
    }

    protected function staff(array $permissions): User
    {
        $role = Role::findOrCreate('system-screens-test');
        $role->syncPermissions(['admin.access', ...$permissions]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user->fresh();
    }

    public function test_each_screen_needs_its_own_permission(): void
    {
        foreach (self::screens() as [$path, $permission]) {
            $this->actingAs($this->staff([]))->get('http://localhost'.$path)->assertForbidden();
            $this->actingAs($this->staff([$permission]))->get('http://localhost'.$path)->assertOk();
        }
    }

    public function test_the_sidebar_and_search_leave_out_what_cannot_be_opened(): void
    {
        $user = $this->staff([]);

        $this->actingAs($user)->get('http://localhost/admin')
            ->assertOk()
            ->assertDontSee('href="http://localhost/admin/currencies"', false)
            ->assertDontSee('href="http://localhost/admin/tasks"', false);

        $this->actingAs($user)->get('http://localhost/admin/search?q=rate')
            ->assertOk()
            ->assertDontSee('/admin/settings', false);
    }
}
