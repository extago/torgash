<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * A stable name for a kit and for a slot (PLAN §33.7) — the same column, for
 * the same reason, as `m_options_groups.code`: an id identifies a row only
 * inside this database, and exim matches on ONE column.
 *
 * Named after the module, because Laravel tracks applied migrations by
 * filename alone and ignores the directory: two modules shipping
 * `..._add_codes.php` would be one migration to the framework.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_kits_kits', function (Blueprint $table) {
            $table->string('code', 190)->nullable()->unique();
        });

        Schema::table('m_kits_slots', function (Blueprint $table) {
            $table->string('code', 190)->nullable()->unique();
        });

        $this->backfill('m_kits_kits', fn ($row) => $row->admin_name);
        // A slot has no name of its own — it is "the thing in this position" —
        // so its kit and its place in it are what it is called.
        $this->backfill('m_kits_slots', fn ($row) => 'slot');
    }

    public function down(): void
    {
        Schema::table('m_kits_slots', fn (Blueprint $table) => $table->dropColumn('code'));
        Schema::table('m_kits_kits', fn (Blueprint $table) => $table->dropColumn('code'));
    }

    protected function backfill(string $table, callable $name): void
    {
        DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $name) {
            foreach ($rows as $row) {
                $base = Str::slug((string) $name($row)) ?: 'row';

                DB::table($table)->where('id', $row->id)
                    ->update(['code' => Str::limit($base.'-'.$row->id, 190, '')]);
            }
        });
    }
};
