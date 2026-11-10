<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The number to ring a customer on, apart from the number that signs in.
 *
 * `users.phone` is written only after a code came back, so it can be unique
 * and open the account. A number typed on an order or in the profile has
 * proven nothing: put there, a stranger's typo would take a number from its
 * real owner, and a shop with no SMS provider could keep none at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('contact_phone', 20)->nullable()->after('phone_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('contact_phone');
        });
    }
};
