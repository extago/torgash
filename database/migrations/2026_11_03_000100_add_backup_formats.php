<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In what form a plan's backups are made (PLAN §67.3): the plan's options
 * (database format, compression, encryption, .env) and its password —
 * encrypted with APP_KEY like a storage's; a run remembers the form it was
 * made in, so its copies are read and deleted the right way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('backup_plans', function (Blueprint $table) {
            $table->jsonb('options')->default('{}');
            $table->text('secret')->nullable();
        });

        Schema::table('backup_runs', function (Blueprint $table) {
            $table->string('format', 16)->default('zip');
            $table->boolean('encrypted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('backup_runs', function (Blueprint $table) {
            $table->dropColumn(['format', 'encrypted']);
        });

        Schema::table('backup_plans', function (Blueprint $table) {
            $table->dropColumn(['options', 'secret']);
        });
    }
};
