<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The shop's own wording of the engine's texts (Admin → Texts): one row per
 * language and English key, read OVER the lang files. In the database, not
 * in a file, so an update that replaces every lang file leaves them be —
 * and a backup carries them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('text_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 8);
            $table->text('key');
            $table->text('value');
            $table->timestamps();

            $table->unique(['locale', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('text_overrides');
    }
};
