<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a search engine shows for a storefront's home page — its title and
 * description, per language ({"en": …, "ru": …}). The home page is the
 * storefront's own (two storefronts, two home pages), not a catalogue entity
 * with meta columns of its own, so the words live on the storefront. Empty
 * title: the storefront's name, as before; empty description: none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->jsonb('home_title')->nullable();
            $table->jsonb('home_description')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('storefronts', function (Blueprint $table) {
            $table->dropColumn(['home_title', 'home_description']);
        });
    }
};
