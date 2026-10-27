<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An option the customer TYPES (PLAN §33.7): an engraving, a name on a shirt,
 * a measurement the workshop needs.
 *
 * Only one new column, because a typed option is the same thing as every other
 * one seen from the shop's side: a group is what is asked, and what it costs is
 * a CHOICE with a price rule — the first one the group holds. So an engraving
 * priced at 200 is a group of type `text` with one choice, and a free "name it
 * yourself" is a group with none. What the customer writes is stored in the
 * cart line's own configuration, which is also what makes "Мама" and "Папа" two
 * lines rather than one of two: the text is part of what was bought.
 *
 * `max_length` is the only thing the shop has to say that a chosen answer never
 * needed — an engraving plate is 20 characters wide, and refusing at the till
 * is worse than refusing in the form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_options_groups', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_length')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_options_groups', function (Blueprint $table) {
            $table->dropColumn('max_length');
        });
    }
};
