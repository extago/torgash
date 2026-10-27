<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What one member of the staff arranged for themselves in the admin — which
 * dashboard widgets they hide and in what order they read the rest. A property
 * of the PERSON (two managers want two dashboards), not a shop setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->jsonb('admin_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('admin_preferences');
        });
    }
};
