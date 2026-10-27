<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Same FILENAME as the fixture module's migration, on purpose. Laravel records
 * applied migrations by filename with no notion of which directory they came
 * from, so without a guard this file would be silently skipped after the other
 * module installed — leaving a module whose table never gets created.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_clash_items', function (Blueprint $table) {
            $table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_clash_items');
    }
};
