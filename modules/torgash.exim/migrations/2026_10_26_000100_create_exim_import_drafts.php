<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An uploaded file on its way to being imported (PLAN §58): the file waits on
 * the private disk at `path`, and its row here keeps what the admin has done
 * with it so far — the mapping (saved as they pick, not only on "Check"), the
 * options, how many rows and columns it has. The import screen lists them as
 * "Unfinished imports"; importing or deleting one ends it, and one nobody
 * touched for ImportDraft::DAYS is swept with its file.
 *
 * `token` is the random part of the screen's address, never the id: the
 * address is what a colleague is sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_exim_import_drafts', function (Blueprint $table) {
            $table->id();
            $table->string('token', 32)->unique();
            $table->string('entity', 32);
            $table->string('path');
            $table->string('file_name');
            $table->unsignedInteger('row_count')->nullable();
            $table->unsignedInteger('column_count')->nullable();
            $table->jsonb('mapping')->nullable();
            $table->jsonb('options')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_exim_import_drafts');
    }
};
