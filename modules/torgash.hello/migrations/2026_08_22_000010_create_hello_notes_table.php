<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Module tables are prefixed m_<module>_ so uninstall can never collide with core.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_hello_notes', function (Blueprint $table) {
            $table->id();
            $table->string('text');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_hello_notes');
    }
};
