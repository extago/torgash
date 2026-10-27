<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin → System (PLAN §45): what the server is, and the backups — making
 * one, downloading the whole database, deleting them. Not simply "in the
 * admin": a backup is every customer's data in one file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('system.manage');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'system.manage')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
