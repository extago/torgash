<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row = one block placed in one zone.
 *
 * `settings` is plain JSON data and nothing else. No template string, no
 * expression, no PHP — the rendering code for a block lives in a file that the
 * block TYPE points at (see BlockTypes), never in this table. That is the whole
 * security position of this module: a write to this table can change what a
 * block says, never what the server executes. Storing a template here (the
 * Smarty-in-block / WordPress PHP-widget shape) would turn any admin
 * write, or any SQL injection anywhere in the engine, into code execution.
 *
 * Because settings are data, they are validated against the type's declared
 * schema on the way in — so the raw-JSON editor in the admin is exactly as
 * safe as the generated form, and neither can introduce a key the type never
 * declared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_blocks', function (Blueprint $table) {
            $table->id();
            // Zone name as declared in a manifest.json / theme.json. Deliberately
            // a plain string and not a foreign key: zones come from files, not
            // from the database, and a block must survive its module being
            // temporarily disabled (it simply stops rendering until it is back).
            $table->string('zone');
            $table->string('type');
            // Admin-facing reference only; what the visitor sees comes from settings.
            $table->string('title')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->unsignedSmallInteger('sort')->default(0);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['zone', 'enabled', 'sort']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_blocks');
    }
};
