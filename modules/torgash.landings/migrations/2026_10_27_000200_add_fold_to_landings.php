<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a long landing folds as the product's description does — at the
 * catalogue's `description_fold_height`, with "Read in full" under it.
 * Off for every existing landing: it was drawn whole when it was made.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_landings', function (Blueprint $table) {
            $table->boolean('fold')->default(false)->after('space_bottom');
        });
    }

    public function down(): void
    {
        Schema::table('m_landings', function (Blueprint $table) {
            $table->dropColumn('fold');
        });
    }
};
