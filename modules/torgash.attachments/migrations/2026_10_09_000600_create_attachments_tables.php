<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentation beside a record — a product today. One table for every owner,
 * keyed by the owner's KEY (`product`) rather than a class name, the way
 * torgash.video keys its rows, so renaming a model never orphans one.
 *
 * A row is EITHER a file of the shop's own (`path` on the private disk, under
 * a name of ours) OR a link to somebody else's (`url`) — the maker's own
 * manual, which the maker keeps up to date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_attachments_files', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 64);
            $table->unsignedBigInteger('owner_id');
            $table->jsonb('title')->nullable();
            $table->string('path', 255)->nullable();
            // The name it was uploaded under: what the download is called,
            // and what stands in for a title nobody typed.
            $table->string('original_name', 190)->nullable();
            $table->string('extension', 16)->nullable();
            $table->string('mime', 127)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('url', 1000)->nullable();
            // `all`, or `account` — signed-in customers only.
            $table->string('access', 16)->default('all');
            $table->unsignedInteger('downloads')->default(0);
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_attachments_files');
    }
};
