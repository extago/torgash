<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a category's page shows the categories under it — tiles, buttons or
 * nothing — when the category says so for itself; NULL is the theme's word
 * (theme setting `subcategories`). And whether a category is one of those
 * on its PARENT's page: left out there, it is still in every menu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->string('subcategories_view', 16)->nullable();
            $table->boolean('shown_in_parent')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_categories', function (Blueprint $table) {
            $table->dropColumn(['subcategories_view', 'shown_in_parent']);
        });
    }
};
