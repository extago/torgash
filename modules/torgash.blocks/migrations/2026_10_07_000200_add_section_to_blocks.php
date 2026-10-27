<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A block's SECTION (PLAN §51): how wide the band it stands in is — the
 * page's width, a background across the whole screen, or everything edge to
 * edge — what is behind it and how much room above and below. A column,
 * beside `row` / `width` / `tabs`: it belongs to where the block stands, not
 * to its type's settings, so every type has it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_blocks', function (Blueprint $table) {
            $table->jsonb('section')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_blocks', function (Blueprint $table) {
            $table->dropColumn('section');
        });
    }
};
