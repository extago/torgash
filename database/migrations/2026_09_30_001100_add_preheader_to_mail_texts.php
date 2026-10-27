<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A letter's PREHEADER: the grey line a mail client shows beside the subject
 * in the inbox, before the letter is opened. Per letter and per language,
 * like its other words; empty = the letter's heading.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_texts', function (Blueprint $table) {
            $table->jsonb('preheader')->nullable()->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('mail_texts', function (Blueprint $table) {
            $table->dropColumn('preheader');
        });
    }
};
