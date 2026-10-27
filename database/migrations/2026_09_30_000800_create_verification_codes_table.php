<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per code a shop sent: to whom, what for, through what, and how it
 * ended (PLAN §44). The same rows are the log on Admin → Verification codes —
 * a second table of "what was sent" would be the same facts twice.
 *
 * The address is kept as typed (normalised) because it has to be printed
 * masked to the customer and in full to the shop; rows age out
 * (App\Core\Verification\Verifier::prune).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->string('purpose', 40);
            $table->string('kind', 10);          // email | phone
            $table->string('address');
            $table->string('channel', 60);
            $table->string('code_hash');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('browser_hash', 64);  // which browser asked (Verifier::browserToken)
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('sends')->default(0);
            $table->string('status', 20)->default('pending');  // sent | failed | invalid_address | unreachable | verified | expired
            $table->string('error', 300)->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();

            $table->index(['kind', 'address', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};
