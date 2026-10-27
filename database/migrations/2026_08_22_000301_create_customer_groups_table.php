<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Core, like addresses — "Users: customers, groups, admins with RBAC" in
// docs/PLAN.md §6 has no "(M)" module marker, unlike payment/shipping.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_groups', function (Blueprint $table) {
            $table->id();
            $table->jsonb('name');
            $table->unsignedTinyInteger('discount_percent')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('customer_group_id')->nullable()->after('id')
                ->constrained('customer_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_group_id');
        });

        Schema::dropIfExists('customer_groups');
    }
};
