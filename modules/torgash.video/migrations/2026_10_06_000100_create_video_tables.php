<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Videos (PLAN §50). One table for every owner — a product today, an article
 * and a page tomorrow — keyed the way torgash.media keys its owners: the
 * owner's registered KEY, not a class name, so renaming a model never
 * orphans a row.
 *
 * What a video IS is `source` + `ref`: the platform's word and what that
 * platform needs to find it (`{"id": "dQw4w9WgXcQ"}`), never the player's
 * HTML. The player is drawn from them at render time, so a new embed address
 * or a new player is one change for every video in the shop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_video_videos', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 64);
            $table->unsignedBigInteger('owner_id');
            $table->string('source', 64);
            $table->jsonb('ref');
            // The address it was added by — shown back to the admin, and what
            // a source that changes its own spelling can be re-read from.
            $table->string('url', 1000)->nullable();
            $table->jsonb('title')->nullable();
            // Seconds, when the platform said; schema.org wants it, nothing else does.
            $table->unsignedInteger('duration')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_video_videos');
    }
};
