<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trash for products and categories. Deleting from the admin now only stamps
 * deleted_at; the record keeps its row, its media and its pivot links so a
 * restore brings back exactly what was there. sku/slug stay unique across
 * trashed rows on purpose — reusing an identifier that a restorable record
 * still holds would silently break the restore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->softDeletes();
        });

        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });

        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
