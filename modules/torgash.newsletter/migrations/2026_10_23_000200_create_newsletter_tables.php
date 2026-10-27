<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per address: whether it is waiting for its confirmation, subscribed
 * or gone; where it came from and what was asked (the consent's evidence:
 * when, from which address, what words); the token its letters' links carry.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('status', 16)->default('pending'); // pending | subscribed | unsubscribed
            $table->foreignId('storefront_id')->nullable()->constrained('storefronts')->nullOnDelete();
            $table->string('locale', 8)->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 24)->default('block'); // block | cart | checkout | account | import | admin
            $table->string('token', 64)->unique();
            $table->string('consent_ip', 45)->nullable();
            $table->text('consent_text')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamp('confirmation_sent_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_newsletter_subscribers');
    }
};
