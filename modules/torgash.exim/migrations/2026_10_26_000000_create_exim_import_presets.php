<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How one supplier's file is read (PLAN §58): which of its columns is which of
 * our fields, and how its cells are written — a preset, as other importers
 * call it; one row with two jsonb maps is enough here.
 *
 * `columns` is the file's header as it was saved — the next file with the
 * same header is recognised by it and needs no setting up.
 * `mapping` is header => field key (or "" for "skip"), `options` the
 * delimiter, encoding, category delimiter, what to do with photos, ...
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_exim_import_presets', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('entity', 32);
            $table->jsonb('columns');
            $table->jsonb('mapping');
            $table->jsonb('options')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('entity');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_exim_import_presets');
    }
};
