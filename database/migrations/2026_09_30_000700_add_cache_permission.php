<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin → Cache and sessions writes .env and, on a sessions switch, signs
 * every customer out — not simply "in the admin".
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('cache.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'cache.manage')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
