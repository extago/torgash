<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 3D models beside a record's photos — a product today. One table for every
 * owner, keyed by the owner's KEY (`product`), like torgash.video.
 *
 * The file is a binary glTF (.glb) on the PUBLIC disk: the viewer fetches it
 * by address, and a model shown to every visitor guards nothing. Its poster —
 * the picture standing in the strip until the model is opened — is a
 * media-library picture of the row (`poster`), with the gallery's sizes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_model3d_models', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 64);
            $table->unsignedBigInteger('owner_id');
            $table->string('path', 255);
            $table->string('original_name', 190)->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->jsonb('title')->nullable();
            $table->integer('position')->default(0);
            $table->timestamps();

            $table->index(['owner_type', 'owner_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_model3d_models');
    }
};
