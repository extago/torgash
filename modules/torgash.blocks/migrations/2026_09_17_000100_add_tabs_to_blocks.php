<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Blocks that stand next to each other and share this word are drawn as one row
 * of tabs (see Torgash\Blocks\ZoneRenderer).
 *
 * A COLUMN and not a setting, for the reason `source` is one: settings belong to
 * the block type's own schema, and "is this one of several tabs" is a question
 * about where a block stands, which any type can be asked. A word and not a
 * foreign key to some "tab set" row, because a tab set has nothing of its own
 * to store — its members, their order and their names are all already on the
 * blocks — and a table holding nothing is a second thing to keep in step.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_blocks', function (Blueprint $table) {
            $table->string('tabs', 64)->nullable()->after('title');
        });
    }

    public function down(): void
    {
        Schema::table('m_blocks', function (Blueprint $table) {
            $table->dropColumn('tabs');
        });
    }
};
