<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * `customers.manage` for a database seeded before it existed. CoreSeeder
 * creates core's permissions on a fresh install; an existing shop only gets a
 * new one through here, and without the row ticking it on the permission
 * group screen would throw. The super group needs nothing — Gate::before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('customers.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'customers.manage')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
