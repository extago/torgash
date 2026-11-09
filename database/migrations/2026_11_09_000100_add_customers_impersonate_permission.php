<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeing the storefront as one of its customers, and ordering in their name,
 * is not something that comes with being able to read their card.
 */
return new class extends Migration
{
    public function up(): void
    {
        Permission::findOrCreate('customers.impersonate');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'customers.impersonate')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
