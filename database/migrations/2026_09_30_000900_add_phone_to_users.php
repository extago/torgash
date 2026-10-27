<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A phone on the account (PLAN §44, step 3): E.164 plus its country
 * (App\Core\Support\Phones — a calling code is not a country). Written only
 * after a code sent to it was typed back, so a stored phone is a proven one
 * and can be unique; `phone_verified_at` still says when, for the day an
 * import or the admin writes one unproven.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->unique()->after('email');
            $table->string('phone_country', 2)->nullable()->after('phone');
            $table->timestamp('phone_verified_at')->nullable()->after('phone_country');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->dropColumn(['phone', 'phone_country', 'phone_verified_at']);
        });
    }
};
