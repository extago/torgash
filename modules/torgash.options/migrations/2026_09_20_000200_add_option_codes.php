<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A stable name for a question and for an answer (PLAN §33.7), so that the
 * configurator can leave the shop in a spreadsheet and come back.
 *
 * Until now a group was identified by its id and nothing else. An id is not an
 * identity anywhere outside this database: a file exported from one shop and
 * imported into another would attach warranties to whatever happened to have
 * those numbers, and even a round trip through the SAME shop breaks the moment
 * a row is deleted and the sequence moves on. `Feature` solved this long ago
 * with `code`, and exim already matches on it; this is the same answer for the
 * same reason.
 *
 * Unique across the table rather than within the parent, because exim matches
 * a row by ONE column — that is the whole shape of `exim.match.*` — and
 * "unique within this group" would need two, plus a rule about which wins.
 *
 * Auto-assigned rather than demanded. A shop that never exports anything must
 * not be made to name two hundred warranty choices, so a code is derived from
 * whatever the row already calls itself, and the admin may type its own when
 * it cares. Existing rows are backfilled here for the same reason: a code that
 * only new rows have is a code nothing can be matched by.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_options_groups', function (Blueprint $table) {
            $table->string('code', 190)->nullable()->unique();
        });

        Schema::table('m_options_choices', function (Blueprint $table) {
            $table->string('code', 190)->nullable()->unique();
        });

        $this->backfill('m_options_groups', fn ($row) => $row->admin_name);
        $this->backfill('m_options_choices', function ($row) {
            $name = (array) json_decode((string) $row->name, true);

            return reset($name) ?: $row->source ?: 'choice';
        });
    }

    public function down(): void
    {
        Schema::table('m_options_choices', fn (Blueprint $table) => $table->dropColumn('code'));
        Schema::table('m_options_groups', fn (Blueprint $table) => $table->dropColumn('code'));
    }

    /**
     * One code per existing row, unique, and never the empty string — a
     * Cyrillic-only name slugs to nothing at all, which is why the id is the
     * fallback rather than an ornament.
     */
    protected function backfill(string $table, callable $name): void
    {
        DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $name) {
            foreach ($rows as $row) {
                $base = Str::slug((string) $name($row)) ?: 'row';
                $code = $base.'-'.$row->id;

                DB::table($table)->where('id', $row->id)->update(['code' => Str::limit($code, 190, '')]);
            }
        });
    }
};
