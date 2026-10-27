<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every record the demo pack created (App\Core\Demo\DemoData, PLAN §55) —
 * so "Remove demo data" removes exactly those, and never what the owner has
 * added since. The class is stored as written, not a morph alias: removal
 * finds the record through it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_records', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->string('model_id');
            $table->timestamp('created_at')->nullable();
            $table->unique(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_records');
    }
};
