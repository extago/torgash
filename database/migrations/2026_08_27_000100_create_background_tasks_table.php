<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracking rows for long-running work that was moved off the request.
 *
 * The queue's own `jobs` table answers "what is pending"; it cannot answer
 * "what did the admin start, how far is it, and did it work" — the payload is
 * an opaque blob and the row disappears the moment the job succeeds. This
 * table is the admin-facing half: one row per started task, surviving the job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('background_tasks', function (Blueprint $table) {
            $table->id();
            // Stored as the English source string (the job's label()), so the
            // admin list renders it through __() like any other UI string.
            $table->string('name');
            $table->string('module')->nullable();
            $table->string('status', 16)->default('queued');
            $table->unsignedInteger('progress')->default(0);
            $table->unsignedInteger('total')->nullable();
            // Message and its placeholders are stored apart for the same reason
            // the name is stored in English: the worker runs in the app's
            // default locale, but the admin reading the list may not be in it.
            $table->string('message')->nullable();
            $table->jsonb('message_params')->nullable();
            $table->text('error')->nullable();
            $table->jsonb('result')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // Postgres does not index the referencing side of a foreign key on
            // its own — see the project rule about FK indexes.
            $table->index('user_id');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('background_tasks');
    }
};
