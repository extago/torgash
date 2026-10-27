<?php

namespace Tests\Feature\Core;

use App\Core\Access\AccessGuard;
use App\Core\Access\PermissionCatalog;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The Administrators / Permission groups screens, and the two things they must
 * never allow: somebody handing themselves more power than they have, and the
 * shop ending up with nobody who can sign in.
 */
class StaffAccessTest extends TestCase
{
    protected User $super;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles and permissions are created inside the test's transaction;
        // spatie's cache does not know that on its own.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->super = User::role(AccessGuard::SUPER_ROLE)->firstOrFail();
    }

    protected function manager(string $group, array $permissions): User
    {
        $role = Role::findOrCreate($group);
        $role->syncPermissions([...$permissions, 'admin.access']);

        $user = User::create(['name' => 'Manager', 'email' => 'manager@torgash.local', 'password' => 'secret123']);
        $user->assignRole($role);

        return $user;
    }

    protected function customer(): User
    {
        return User::create(['name' => 'Buyer', 'email' => 'buyer@example.com', 'password' => 'secret123']);
    }

    /**
     * The regression this whole screen was blocked on: "customer" was defined
     * as "not the admin role", which was only ever true while `admin` was the
     * one role that existed.
     */
    public function test_a_manager_is_staff_not_a_customer(): void
    {
        $manager = $this->manager('sales', ['orders.manage']);
        $customer = $this->customer();

        $this->actingAs($this->super)
            ->get('http://localhost/admin/customers')
            ->assertOk()
            ->assertSee($customer->email)
            ->assertDontSee($manager->email);

        $this->actingAs($this->super)
            ->get('http://localhost/admin/administrators')
            ->assertOk()
            ->assertSee($manager->email)
            ->assertDontSee($customer->email);
    }

    public function test_an_administrator_cannot_be_created_without_a_group(): void
    {
        $this->actingAs($this->super)
            ->post('http://localhost/admin/administrators', [
                'name' => 'Nobody',
                'email' => 'nobody@torgash.local',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
            ])
            ->assertSessionHasErrors('roles');

        $this->assertNull(User::where('email', 'nobody@torgash.local')->first());
    }

    public function test_a_created_administrator_holds_its_group_and_is_not_listed_as_a_customer(): void
    {
        Role::findOrCreate('sales')->syncPermissions(['admin.access', 'orders.manage']);

        $this->actingAs($this->super)
            ->post('http://localhost/admin/administrators', [
                'name' => 'Sam',
                'email' => 'sam@torgash.local',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'roles' => ['sales'],
                // "Save and close" from the gear; a plain Save stays on the form.
                'after_save' => 'close',
            ])
            ->assertRedirect(route('admin.administrators.index'));

        $created = User::where('email', 'sam@torgash.local')->firstOrFail();

        $this->assertTrue($created->hasRole('sales'));
        $this->assertTrue($created->can('orders.manage'));
        $this->assertFalse($created->can('catalog.manage'));
        $this->assertFalse(User::query()->customers()->whereKey($created->id)->exists());
    }

    public function test_the_screens_are_closed_without_the_staff_permission(): void
    {
        $manager = $this->manager('sales', ['orders.manage']);

        $this->actingAs($manager)->get('http://localhost/admin/administrators')->assertForbidden();
        $this->actingAs($manager)->get('http://localhost/admin/permission-groups')->assertForbidden();
    }

    public function test_a_manager_cannot_grant_the_super_group(): void
    {
        $manager = $this->manager('hr', ['staff.manage']);

        $this->actingAs($manager)
            ->post('http://localhost/admin/administrators', [
                'name' => 'Sneaky',
                'email' => 'sneaky@torgash.local',
                'password' => 'secret123',
                'password_confirmation' => 'secret123',
                'roles' => [AccessGuard::SUPER_ROLE],
            ])
            ->assertSessionHasErrors('roles.0');

        $this->assertNull(User::where('email', 'sneaky@torgash.local')->first());
    }

    public function test_a_manager_cannot_open_a_super_admins_account(): void
    {
        $manager = $this->manager('hr', ['staff.manage']);

        $this->actingAs($manager)
            ->get('http://localhost/admin/administrators/'.$this->super->id.'/edit')
            ->assertForbidden();
    }

    public function test_a_manager_cannot_put_a_permission_they_lack_into_a_group(): void
    {
        $manager = $this->manager('hr', ['staff.manage']);

        $this->actingAs($manager)
            ->post('http://localhost/admin/permission-groups', [
                'name' => 'Everything',
                'permissions' => ['catalog.manage'],
            ])
            ->assertSessionHasErrors('permissions.0');

        $this->assertNull(Role::where('name', 'Everything')->first());
    }

    public function test_a_manager_can_build_a_group_out_of_what_they_hold(): void
    {
        $manager = $this->manager('hr', ['staff.manage', 'orders.manage']);

        $this->actingAs($manager)
            ->post('http://localhost/admin/permission-groups', [
                'name' => 'Order desk',
                'permissions' => ['orders.manage'],
            ])
            // Saving stays on the form — now the new group's own.
            ->assertRedirect(route('admin.permission-groups.edit', Role::where('name', 'Order desk')->value('id')));

        $this->assertTrue(Role::findByName('Order desk')->hasPermissionTo('orders.manage'));
    }

    public function test_a_customer_is_not_reachable_through_the_administrators_screen(): void
    {
        $customer = $this->customer();

        $this->actingAs($this->super)
            ->get('http://localhost/admin/administrators/'.$customer->id.'/edit')
            ->assertNotFound();
    }

    public function test_you_cannot_change_your_own_groups(): void
    {
        $manager = $this->manager('hr', ['staff.manage']);

        $this->actingAs($manager)
            ->put('http://localhost/admin/administrators/'.$manager->id, [
                'name' => 'Manager',
                'email' => 'manager@torgash.local',
                'roles' => [],
            ])
            ->assertRedirect(route('admin.administrators.edit', $manager));

        $this->assertTrue($manager->fresh()->hasRole('hr'));
    }

    public function test_you_cannot_delete_your_own_account(): void
    {
        $manager = $this->manager('hr', ['staff.manage']);

        $this->actingAs($manager)
            ->delete('http://localhost/admin/administrators/'.$manager->id)
            ->assertSessionHasErrors('name');

        $this->assertNotNull(User::find($manager->id));
    }

    public function test_a_group_with_members_cannot_be_deleted(): void
    {
        $manager = $this->manager('sales', ['orders.manage']);
        $group = Role::findByName('sales');

        $this->actingAs($this->super)
            ->delete('http://localhost/admin/permission-groups/'.$group->id)
            ->assertSessionHasErrors('name');

        $this->assertNotNull(Role::find($group->id));
        $this->assertTrue($manager->fresh()->hasRole('sales'));
    }

    public function test_the_super_group_is_neither_renamed_nor_deleted(): void
    {
        $group = Role::findByName(AccessGuard::SUPER_ROLE);

        $this->actingAs($this->super)
            ->put('http://localhost/admin/permission-groups/'.$group->id, ['name' => 'Owner'])
            ->assertRedirect(route('admin.permission-groups.index'));

        $this->assertSame(AccessGuard::SUPER_ROLE, $group->fresh()->name);

        $this->actingAs($this->super)
            ->delete('http://localhost/admin/permission-groups/'.$group->id)
            ->assertSessionHasErrors('name');

        $this->assertNotNull(Role::find($group->id));
    }

    /** A new group may not squat on the name Gate::before matches. */
    public function test_a_group_cannot_be_named_after_the_super_group(): void
    {
        $this->actingAs($this->super)
            ->post('http://localhost/admin/permission-groups', ['name' => AccessGuard::SUPER_ROLE])
            ->assertSessionHasErrors('name');
    }

    public function test_every_screen_renders(): void
    {
        $manager = $this->manager('sales', ['orders.manage']);
        $sales = Role::findByName('sales');
        $super = Role::findByName(AccessGuard::SUPER_ROLE);

        foreach ([
            'administrators/create',
            'administrators/'.$manager->id.'/edit',
            'permission-groups',
            'permission-groups/create',
            'permission-groups/'.$sales->id.'/edit',
            'permission-groups/'.$super->id.'/edit',
        ] as $path) {
            $this->actingAs($this->super)->get('http://localhost/admin/'.$path)->assertOk();
        }

        $this->actingAs($this->super)
            ->get('http://localhost/admin/administrators/'.$this->super->id.'/edit')
            ->assertOk()
            ->assertSee('You cannot change your own permissions.', false);
    }

    public function test_the_catalogue_groups_permissions_by_module_and_uses_the_manifest_label(): void
    {
        $groups = collect(app(PermissionCatalog::class)->groups());

        $catalog = $groups->firstWhere('module', 'torgash.catalog');
        $this->assertNotNull($catalog, 'The catalog module should own a section.');
        $this->assertSame(
            'Manage the catalogue: categories, products, prices',
            collect($catalog['permissions'])->firstWhere('name', 'catalog.manage')['label'],
        );

        // Core's own permissions are listed the same way, without a manifest.
        $core = $groups->firstWhere('module', 'core');
        $this->assertContains('staff.manage', collect($core['permissions'])->pluck('name')->all());
    }

    /**
     * What a module knows about staff reaches the list through core's seam;
     * the guard's second factor is the one there is today.
     */
    public function test_the_list_says_who_has_a_second_factor(): void
    {
        $this->actingAs($this->super)
            ->get('http://localhost/admin/administrators')
            ->assertOk()
            ->assertSee('Two-factor: off');
    }

    public function test_the_list_narrows_to_one_group(): void
    {
        $this->manager('hr', ['staff.manage']);

        $this->actingAs($this->super)
            ->get('http://localhost/admin/administrators?group=hr')
            ->assertOk()
            ->assertSee('manager@torgash.local')
            // Not by email: the header's own menu prints the signed-in one.
            ->assertDontSee('administrators/'.$this->super->id.'/edit');
    }
}
