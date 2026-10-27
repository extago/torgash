<?php

namespace Tests\Feature\Core;

use App\Core\Admin\Dashboard;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The dashboard is widgets from whoever owns the data: each drawn only for
 * someone allowed to see it, arranged and hidden per person, and one that
 * throws does not take the page down.
 */
class DashboardTest extends TestCase
{
    protected function admin(): User
    {
        return User::role('admin')->firstOrFail();
    }

    public function test_the_modules_widgets_are_drawn(): void
    {
        $this->actingAs($this->admin())->get('http://localhost/admin')
            ->assertOk()
            ->assertSee('data-widget="checkout.sales"', false)
            ->assertSee('data-widget="checkout.latest"', false)
            ->assertSee('data-widget="catalog.stock"', false)
            ->assertSee('data-widget="core.attention"', false);
    }

    public function test_a_widget_is_drawn_only_for_someone_allowed_to_see_it(): void
    {
        $role = Role::findOrCreate('dashboard-test');
        $role->syncPermissions(['admin.access']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user->fresh())->get('http://localhost/admin')
            ->assertOk()
            ->assertDontSee('data-widget="checkout.sales"', false)
            ->assertSee('data-widget="core.attention"', false);
    }

    public function test_hiding_and_arranging_belong_to_the_person(): void
    {
        $admin = $this->admin();
        $other = User::factory()->create();
        $other->assignRole('admin');

        $this->actingAs($admin)->post('http://localhost/admin/dashboard/layout', ['hide' => 'checkout.sales'])->assertRedirect();

        $this->actingAs($admin->fresh())->get('http://localhost/admin')->assertDontSee('data-widget="checkout.sales"', false);
        $this->actingAs($other->fresh())->get('http://localhost/admin')->assertSee('data-widget="checkout.sales"', false);

        // Arranged: three columns, the sales across the top, the stock
        // first in the third column, and everything shown again.
        $this->actingAs($admin->fresh())->post('http://localhost/admin/dashboard/layout', [
            'layout' => 'thirds',
            'zones' => ['top' => ['checkout.sales'], 'c2' => ['catalog.stock', 'checkout.latest']],
            'hidden' => [],
        ])->assertRedirect();

        $board = app(Dashboard::class)->board($admin->fresh());
        $this->assertSame('thirds', $board['layout']);
        $this->assertSame(['checkout.sales'], $board['zones']['top']);
        $this->assertSame(['catalog.stock', 'checkout.latest'], array_slice($board['zones']['c2'], 0, 2));
        $this->assertSame([], $board['hidden']);

        // Fewer columns later: what stood in the third folds into the last one left.
        $this->actingAs($admin->fresh())->post('http://localhost/admin/dashboard/layout', [
            'layout' => 'main-side',
            'zones' => ['c2' => ['catalog.stock']],
        ]);
        $this->assertContains('catalog.stock', app(Dashboard::class)->board($admin->fresh())['zones']['c1']);
    }

    public function test_a_widget_that_throws_does_not_take_the_page_down(): void
    {
        hook_listen('core.admin.dashboard.widgets', fn (array $widgets) => [...$widgets, [
            'key' => 'tests.broken',
            'title' => 'Broken',
            'render' => fn () => throw new \RuntimeException('boom'),
        ]]);

        $this->actingAs($this->admin())->get('http://localhost/admin')
            ->assertOk()
            ->assertSee('data-widget="tests.broken"', false)
            ->assertSee('data-widget="checkout.sales"', false);
    }
}
