<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A "look": a picture of a room or an outfit with points on the products in
 * it — `points` is a jsonb ARRAY (order matters: it is the reading order)
 * of {x, y, product_id}, x and y in percent of the picture.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_collection_looks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained('m_collections')->cascadeOnDelete();
            $table->string('image');
            $table->jsonb('caption')->nullable();
            $table->jsonb('points')->nullable();
            $table->integer('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_collection_looks');
    }
};
