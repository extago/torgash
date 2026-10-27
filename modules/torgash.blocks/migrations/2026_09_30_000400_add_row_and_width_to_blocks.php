<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columns in a zone, the way tabs already are: a word on NEIGHBOURING blocks,
 * never a block that holds blocks. Adjacent blocks sharing `row` are drawn side
 * by side (ZoneRenderer), each `width` wide — a fraction of the row, or NULL
 * for "share what is left equally". Phones always stack them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_blocks', function (Blueprint $table) {
            $table->string('row', 64)->nullable();
            $table->string('width', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_blocks', function (Blueprint $table) {
            $table->dropColumn(['row', 'width']);
        });
    }
};
