<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which languages an export profile writes its translatable columns in.
 * NULL = every language the shop has, which is what a profile saved before
 * this did for the two it knew.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_exim_profiles', function (Blueprint $table) {
            $table->jsonb('locales')->nullable()->after('fields');
        });
    }

    public function down(): void
    {
        Schema::table('m_exim_profiles', function (Blueprint $table) {
            $table->dropColumn('locales');
        });
    }
};
