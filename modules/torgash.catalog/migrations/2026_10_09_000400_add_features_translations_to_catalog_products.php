<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A product's own (individual) characteristics in other languages.
 *
 * `features` stays what it is — a map in the BASE language, the one exim,
 * filters and every lookup read — and its words in another language live
 * beside it, per language: `{"ru": {"k": {key: label}, "v": {key: value},
 * "s": {section: heading}}}`. Keyed by the base key and the base section
 * heading, so reordering or adding a row never moves a translation onto the
 * wrong line, and a row without one simply shows the base words.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->jsonb('features_translations')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_catalog_products', function (Blueprint $table) {
            $table->dropColumn('features_translations');
        });
    }
};
