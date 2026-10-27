<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A rule is trigger → delay → conditions → actions, the shape a merchant
 * already knows from every CRM that has automations.
 *
 * Conditions and actions are rows rather than one jsonb blob on the rule: they
 * are ordered, they are edited one at a time on the screen, and conditions are
 * meant to be readable back in plain words. That is the same shape the shipping
 * module's method conditions use, and for the same reasons.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_automations_rules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('enabled')->default(true);

            // The hook this rule listens to, e.g. "checkout.order.changed".
            $table->string('event');

            // Optional narrowing for the diff-carrying event: only fire when one
            // of THESE fields moved. Null means any change at all.
            $table->jsonb('fields')->nullable();

            $table->unsignedInteger('delay_minutes')->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['event', 'enabled']);
        });

        Schema::create('m_automations_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('m_automations_rules')->cascadeOnDelete();
            $table->string('kind');
            $table->string('operator');
            $table->jsonb('value')->nullable();
            $table->unsignedInteger('position')->default(0);
        });

        Schema::create('m_automations_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('m_automations_rules')->cascadeOnDelete();
            $table->string('type');
            $table->jsonb('config')->nullable();
            $table->unsignedInteger('position')->default(0);
        });

        /**
         * What actually happened, and what came back.
         *
         * The single most useful thing a webhook engine can have: without it,
         * "did it arrive?" is answerable only from the other side's logs, and a
         * rule that silently stopped firing looks exactly like a rule nobody
         * triggered. Kept for a configurable number of days and swept nightly.
         */
        Schema::create('m_automations_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->nullable()->constrained('m_automations_rules')->nullOnDelete();
            $table->string('rule_name');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('status', 16);
            $table->string('summary')->nullable();
            $table->jsonb('detail')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['rule_id', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_automations_runs');
        Schema::dropIfExists('m_automations_actions');
        Schema::dropIfExists('m_automations_conditions');
        Schema::dropIfExists('m_automations_rules');
    }
};
