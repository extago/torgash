<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The one-line answer to "what is this?", and which characteristics compose it.
 *
 * Two columns for one feature, and the split is the point (PLAN §30.10).
 * `short_description` is what a person WROTE — jsonb, like every other
 * translatable text — and it is empty on most of a catalogue, because nobody
 * writes three thousand of them. `in_short_description` is the shop saying
 * which characteristics stand in for it where it is blank: memory and screen
 * size for a laptop, not the warranty period.
 *
 * The flag lives on the characteristic rather than in a setting listing codes,
 * for the reason everything else about a characteristic does: it is edited
 * where the characteristic is, it follows the feature↔category assignment
 * (a laptop's memory is offered on laptops), and a renamed code does not
 * silently empty a shop-wide list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->jsonb('short_description')->nullable()->after('description');
        });

        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->boolean('in_short_description')->default(false)->after('purpose');
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn('short_description');
        });

        Schema::table('m_catalog_features', function (Blueprint $table) {
            $table->dropColumn('in_short_description');
        });
    }
};
