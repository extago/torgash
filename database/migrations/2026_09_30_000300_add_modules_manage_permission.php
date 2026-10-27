<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * `modules.manage` for a database seeded before it existed — until now any
 * member of the staff could uninstall a module, which drops its tables. The
 * super group needs nothing (Gate::before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('modules.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'modules.manage')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
