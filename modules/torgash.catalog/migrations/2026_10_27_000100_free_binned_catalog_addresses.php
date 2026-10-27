<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Products and categories already in the bin give their addresses up, as
 * KeepsAddress does from now on for every new deletion: `<slug>-deleted-<id>`.
 * A restore takes the address back when it is still free.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['m_catalog_products', 'm_catalog_categories'] as $table) {
            DB::table($table)->whereNotNull('deleted_at')
                ->whereRaw("slug not like '%-deleted-' || id::text")
                ->update(['slug' => DB::raw("slug || '-deleted-' || id::text")]);
        }
    }

    public function down(): void
    {
        foreach (['m_catalog_products', 'm_catalog_categories'] as $table) {
            DB::table($table)->whereNotNull('deleted_at')
                ->whereRaw("slug like '%-deleted-' || id::text")
                ->update(['slug' => DB::raw("left(slug, length(slug) - length('-deleted-' || id::text))")]);
        }
    }
};
