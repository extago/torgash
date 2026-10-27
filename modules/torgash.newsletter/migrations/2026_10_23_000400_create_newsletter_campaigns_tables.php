<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campaigns (PLAN §57.4): a letter to a segment of the subscribers, now or at
 * a time. A campaign's words are per language (the subscriber's own, else the
 * shop's base); `segment` is {kind, params} of the segments registry.
 * A delivery is one address of one campaign — the queue the sender walks and
 * the row its letter's open pixel, links and "unsubscribe" write to. A link
 * is every address the letter points at, counted; the click route redirects
 * only to a link stored here, never to what the request asks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_newsletter_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->jsonb('subject')->nullable();
            $table->jsonb('preheader')->nullable();
            $table->jsonb('heading')->nullable();
            $table->jsonb('body')->nullable();
            $table->foreignId('storefront_id')->nullable()->constrained('storefronts')->nullOnDelete();
            $table->jsonb('segment')->nullable();
            $table->string('status', 16)->default('draft'); // draft | scheduled | sending | sent | cancelled
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->unsignedInteger('opened')->default(0);
            $table->unsignedInteger('clicked')->default(0);
            $table->unsignedInteger('unsubscribed')->default(0);
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });

        Schema::create('m_newsletter_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('m_newsletter_campaigns')->cascadeOnDelete();
            $table->foreignId('subscriber_id')->nullable()->constrained('m_newsletter_subscribers')->nullOnDelete();
            $table->string('email');
            $table->string('locale', 8)->nullable();
            $table->string('token', 40)->unique();
            $table->string('status', 16)->default('queued'); // queued | sent | failed | skipped
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();

            $table->unique(['campaign_id', 'email']);
            $table->index(['campaign_id', 'status']);
        });

        Schema::create('m_newsletter_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('m_newsletter_campaigns')->cascadeOnDelete();
            $table->string('hash', 40);
            $table->text('url');
            $table->unsignedInteger('clicks')->default(0);

            $table->unique(['campaign_id', 'hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_newsletter_links');
        Schema::dropIfExists('m_newsletter_deliveries');
        Schema::dropIfExists('m_newsletter_campaigns');
    }
};
