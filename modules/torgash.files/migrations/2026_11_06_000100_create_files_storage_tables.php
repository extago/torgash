<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The file storage (torgash.files): folders and the files in them.
 *
 * Folders exist only here, never on the disk. A file is stored flat under
 * `files/` on the PUBLIC disk by a name of ours (`path`), and the name the
 * staff see (`name`) is a column — so renaming a file or moving it to another
 * folder never changes its link. That is the point of the module: the link is
 * pasted into a block, a product's "photos for Avito" field, a feed, and must
 * keep working whatever is tidied up here later. On S3 it also means a move
 * is a row update, not a copy of the bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_files_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('m_files_folders')->cascadeOnDelete();
            $table->string('name', 120);
            $table->timestamps();

            $table->index('parent_id');
        });

        Schema::create('m_files_files', function (Blueprint $table) {
            $table->id();
            // Deleting a folder deletes its files through the model (their
            // bytes go with them); the cascade only keeps the table honest.
            $table->foreignId('folder_id')->nullable()->constrained('m_files_folders')->cascadeOnDelete();
            $table->string('name');
            $table->string('path')->unique();
            $table->string('thumb_path')->nullable();
            $table->string('extension', 10);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['folder_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_files_files');
        Schema::dropIfExists('m_files_folders');
    }
};
