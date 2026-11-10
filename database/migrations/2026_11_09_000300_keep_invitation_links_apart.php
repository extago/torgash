<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Invitations get a table of their own (`PasswordLinks`): sharing one with the
 * lost-password links is what let a one-hour link be read as a week-long one.
 *
 * An hour is all a lost-password link lasts, so an older row can only be an
 * invitation and moves. A younger one could be either and is kept in both for
 * the few minutes it has left: dropping it would be a dead link in the letter
 * of somebody invited just before the update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_invite_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        DB::statement('insert into password_invite_tokens (email, token, created_at) select email, token, created_at from password_reset_tokens');

        DB::table('password_reset_tokens')->where('created_at', '<', now()->subHour())->delete();
    }

    public function down(): void
    {
        DB::statement('insert into password_reset_tokens (email, token, created_at) select email, token, created_at from password_invite_tokens on conflict (email) do nothing');

        Schema::dropIfExists('password_invite_tokens');
    }
};
