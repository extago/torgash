<?php

use App\Core\Support\GrammaticalCases;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A language's grammatical cases, declared on the language itself — see
 * GrammaticalCases. NULL is "not said", an empty list "does not decline"; the
 * known defaults are filled in only where nothing was said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->jsonb('cases')->nullable();
        });

        GrammaticalCases::seedDefaults();
    }

    public function down(): void
    {
        Schema::table('languages', function (Blueprint $table) {
            $table->dropColumn('cases');
        });
    }
};
