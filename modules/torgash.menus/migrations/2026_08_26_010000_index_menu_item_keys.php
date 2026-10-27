<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * m_menu_items already has (menu_id, parent_id, sort) for rendering a menu.
 * These two cover the lookups that do NOT start from a menu: deleting an item
 * checks its children by parent_id alone, and deleting a category checks
 * source_category_id — both foreign keys Postgres would otherwise verify with
 * a full scan while holding the delete's lock.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create index if not exists m_menu_items_parent_id_index on m_menu_items (parent_id)');
        DB::statement('create index if not exists m_menu_items_source_category_id_index on m_menu_items (source_category_id)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists m_menu_items_parent_id_index');
        DB::statement('drop index if exists m_menu_items_source_category_id_index');
    }
};
