<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Core-side counterpart of the per-module FK index migrations. Postgres does
 * not index the referencing column of a foreign key, so each of these was both
 * a sequential scan for the lookup that uses it and a sequential scan every
 * time the parent row was deleted:
 *
 *  - addresses.user_id          — the customer's address book, read on every
 *                                 checkout and profile page.
 *  - users.customer_group_id    — group pricing resolves a user's group, and
 *                                 deleting a group checks every user.
 *  - settings.storefront_id     — per-storefront overrides; settings(module,
 *                                 storefront_id) already covers the reads, this
 *                                 covers deleting a storefront.
 *
 * spatie's own role_has_permissions.role_id is left alone deliberately: it is a
 * vendor-owned table, its rows number in the dozens, and patching another
 * package's schema from here is exactly the kind of thing that breaks on their
 * next migration.
 */
return new class extends Migration
{
    protected array $indexes = [
        'addresses' => 'user_id',
        'users' => 'customer_group_id',
        'settings' => 'storefront_id',
    ];

    public function up(): void
    {
        foreach ($this->indexes as $table => $column) {
            DB::statement("create index if not exists {$table}_{$column}_index on {$table} ({$column})");
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as $table => $column) {
            DB::statement("drop index if exists {$table}_{$column}_index");
        }
    }
};
