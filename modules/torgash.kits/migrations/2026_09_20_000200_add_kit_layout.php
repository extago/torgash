<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the goods of a kit stand on a wide screen.
 *
 * Two answers, and they are answers to different shops rather than to
 * different tastes:
 *
 * - `grid` — rows of equal cells that wrap. A set of six accessories reads as
 *   a list somebody can take in, and nothing ever leaves the card.
 * - `scroller` — ONE line that scrolls sideways when it does not fit. A set is
 *   a sentence — phone plus glass plus case — and a sentence broken across
 *   lines stops being one; the plus signs only mean anything while the goods
 *   are in a row.
 *
 * `grid` is the default because it is the one that cannot look broken: a
 * scroller a visitor does not notice is goods they never saw.
 *
 * Named after the module, because Laravel tracks applied migrations by
 * filename alone and ignores the directory.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_kits_kits', function (Blueprint $table) {
            $table->string('layout', 20)->default('grid');
        });
    }

    public function down(): void
    {
        Schema::table('m_kits_kits', fn (Blueprint $table) => $table->dropColumn('layout'));
    }
};
