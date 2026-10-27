<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * `storefronts.manage` for a database seeded before it existed — until now any
 * member of the staff could re-point a domain or change the currency every
 * stored amount is read in. The super group needs nothing (Gate::before).
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('storefronts.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'storefronts.manage')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
