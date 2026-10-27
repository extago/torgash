<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a redirect came from and whether anybody still arrives through it.
 *
 * `source` says who wrote the row — the shop on a slug change (`auto`), a
 * person (`manual`), a file (`import`); NULL is a row from before anybody
 * asked. `hits` + `last_hit_at` are the answer a migration needs a month
 * later: which of the old engine's nine thousand addresses the world still
 * uses, and which can be let go. Counted only when a redirect is SERVED.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('redirects', function (Blueprint $table) {
            $table->string('source', 16)->nullable()->after('to_path');
            $table->unsignedInteger('hits')->default(0)->after('source');
            $table->timestamp('last_hit_at')->nullable()->after('hits');
        });
    }

    public function down(): void
    {
        Schema::table('redirects', function (Blueprint $table) {
            $table->dropColumn(['source', 'hits', 'last_hit_at']);
        });
    }
};
