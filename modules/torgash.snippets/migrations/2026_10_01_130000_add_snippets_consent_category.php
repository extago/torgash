<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a snippet is, as consent sees it (PLAN §49): necessary, functional,
 * statistics or marketing — and whether it waits for a click on its own
 * button instead (a chat). Existing snippets become `necessary`, so nothing a
 * shop already has stops working the moment this runs; a privacy module lists
 * them for sorting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_snippets_snippets', function (Blueprint $table) {
            $table->string('category', 16)->default('necessary');
            $table->boolean('on_click')->default(false);
            $table->jsonb('click_label')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_snippets_snippets', function (Blueprint $table) {
            $table->dropColumn(['category', 'on_click', 'click_label']);
        });
    }
};
