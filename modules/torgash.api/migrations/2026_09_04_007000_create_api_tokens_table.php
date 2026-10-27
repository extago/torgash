<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who may call the API, and as whom.
 *
 * A token belongs to a USER, and the permissions that user has are the
 * permissions the call has — the same spatie roles the admin panel checks.
 * Inventing a second permission system for machines is how a shop ends up with
 * an integration that can do things no human account may.
 *
 * Only the HASH is stored. The token is shown once, at issue, and never again;
 * a database dump therefore cannot be replayed against the API. `abilities`
 * narrows a token further than its owner ("this one may only read orders"),
 * because an integration that only reads should not hold a key that can write.
 *
 * `expires_at` is nullable: a permanent token is a legitimate choice for a
 * server-to-server exchange, and forcing a rotation nobody automated is how the
 * exchange silently stops one night.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // What it is for, in the admin's words — "1C exchange", "price feed".
            $table->string('name');

            // sha256 of the plaintext: unique so a lookup is one indexed query
            // rather than a scan comparing every row.
            $table->string('token_hash', 64)->unique();

            $table->jsonb('abilities')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_api_tokens');
    }
};
