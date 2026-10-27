<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A block's own CSS classes, for the shop's stylesheet to find it by
 * (`.home-hits`), added to the block's outermost element. The band a block
 * stands in takes its classes in `section` (jsonb), beside its width and
 * background.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_blocks', function (Blueprint $table) {
            $table->string('css_class', 190)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_blocks', function (Blueprint $table) {
            $table->dropColumn('css_class');
        });
    }
};
