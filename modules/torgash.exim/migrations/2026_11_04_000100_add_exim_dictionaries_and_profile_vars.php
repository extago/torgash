<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A profile's variables (PLAN §68.1): the values its template reads as
 * `vars.<name>` — the delivery cost, the pickup flag — typed beside the
 * template rather than inside it. (The file's name still says
 * "dictionaries": a shared-dictionaries screen was made and taken out the
 * same day; its code is on the branch `shelf/exim-dictionaries`.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_exim_profiles', function (Blueprint $table) {
            $table->jsonb('vars')->nullable(); // {name: value}
        });
    }

    public function down(): void
    {
        Schema::table('m_exim_profiles', function (Blueprint $table) {
            $table->dropColumn('vars');
        });
    }
};
