<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Settings, currencies, languages, redirects and background tasks each behind
 * a permission of its own — until now any member of the staff could change a
 * rate that reprices the catalogue, or re-send every failed letter. The super
 * group needs nothing (Gate::before).
 */
return new class extends Migration
{
    protected array $names = ['settings.manage', 'currencies.manage', 'languages.manage', 'redirects.manage', 'tasks.manage'];

    public function up(): void
    {
        foreach ($this->names as $name) {
            Permission::findOrCreate($name);
        }
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', $this->names)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
