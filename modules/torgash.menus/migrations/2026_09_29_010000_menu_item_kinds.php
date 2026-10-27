<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PLAN §39: a menu item stops being "a link, or a category's children" and
 * becomes a KIND from a registry (link / page / category / heading / divider,
 * more from modules), plus what every kind can carry — an icon, a badge, a
 * highlight, which screens it is for, and core's display rules.
 *
 * `use_source_link` goes: a category item now takes the category's own name
 * and address unless a name or a URL is typed, which is the same switch
 * expressed by the fields themselves. The first migration seeded category
 * items with a COPY of the category's name, so a row that borrowed the link
 * gets its copy cleared — otherwise renaming the category would stop reaching
 * the menu the day this runs.
 *
 * `options` is the kind's own settings (a category's depth, availability rule,
 * limit…), jsonb because that list is the one that grows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_menu_items', function (Blueprint $table) {
            $table->string('kind', 32)->default('link');
            // The kind's argument: a shop page's key (`route:catalog.brands.index`, `page:12`).
            $table->string('target', 190)->nullable();
            $table->string('icon', 64)->nullable();
            $table->jsonb('badge')->nullable();
            $table->string('badge_style', 16)->nullable();
            $table->boolean('highlight')->default(false);
            // all | desktop | mobile — a CSS class, the server does not know the device.
            $table->string('show_on', 8)->default('all');
            $table->jsonb('rules')->nullable();
            $table->string('rules_match', 3)->default('all');
            $table->jsonb('options')->nullable();
        });

        DB::table('m_menu_items')->where('source_type', 'category')->update(['kind' => 'category']);
        DB::table('m_menu_items')->where('source_type', 'category')->where('use_source_link', true)
            ->update(['name' => '{}', 'url' => null]);

        Schema::table('m_menu_items', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'use_source_link']);
        });
    }

    public function down(): void
    {
        Schema::table('m_menu_items', function (Blueprint $table) {
            $table->string('source_type')->default('none');
            $table->boolean('use_source_link')->default(true);
        });

        DB::table('m_menu_items')->where('kind', 'category')->update(['source_type' => 'category']);

        Schema::table('m_menu_items', function (Blueprint $table) {
            $table->dropColumn(['kind', 'target', 'icon', 'badge', 'badge_style', 'highlight', 'show_on', 'rules', 'rules_match', 'options']);
        });
    }
};
