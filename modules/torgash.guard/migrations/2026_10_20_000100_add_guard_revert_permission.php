<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * `guard.revert` for a shop that installed the guard before it existed —
 * an install gets it from the manifest anyway. A permission of its own
 * rather than part of `guard.manage`: reading who changed a price is not
 * the right to change it back. The super group needs nothing (Gate::before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('guard.revert');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'guard.revert')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
