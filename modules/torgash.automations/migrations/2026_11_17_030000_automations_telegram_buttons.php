<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buttons under a Telegram message (PLAN §64.1).
 *
 * - `telegram_bots` — a bot the shop HEARS from, not only writes through: its
 *   token (encrypted — a rule's own copy stays where it was), how presses
 *   reach us (`poll` — we ask; `webhook` — Telegram calls) and how far the
 *   asking has read.
 * - `telegram_users` — which staff account a Telegram account is: a press is
 *   that person's act, checked against their rights every time.
 * - `telegram_messages` — a message sent WITH buttons: what it was about,
 *   what each button does, and who pressed which. Pressed once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_automations_telegram_bots', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->text('token');
            $table->string('username', 190)->nullable();
            $table->string('mode', 16)->default('poll');
            $table->string('secret', 64);
            $table->bigInteger('update_offset')->default(0);
            $table->timestamp('heard_at')->nullable();
            $table->timestamps();
        });

        Schema::create('m_automations_telegram_users', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $table->bigInteger('telegram_id')->unique();
            $table->string('telegram_name', 190)->nullable();
            $table->timestamps();
        });

        Schema::create('m_automations_telegram_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bot_id')->constrained('m_automations_telegram_bots')->cascadeOnDelete();
            $table->unsignedBigInteger('rule_id')->nullable();
            $table->string('rule_name')->nullable();
            $table->string('subject', 32);
            $table->unsignedBigInteger('subject_id');
            $table->string('chat_id', 64);
            $table->bigInteger('message_id')->nullable();
            $table->text('text');
            // [{label, do}] as they were when the message went out.
            $table->jsonb('buttons');
            $table->smallInteger('pressed')->nullable();
            $table->unsignedBigInteger('pressed_by')->nullable();
            $table->timestamp('pressed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index(['subject', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_automations_telegram_messages');
        Schema::dropIfExists('m_automations_telegram_users');
        Schema::dropIfExists('m_automations_telegram_bots');
    }
};
