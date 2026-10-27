<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ways to deliver a verification code by an HTTP request (PLAN §44.3): an SMS
 * gateway, a messenger's API — one row each, no module each. `secrets` is
 * encrypted by the model; nothing else here is a credential, because a
 * template refers to a secret by NAME (`{{ secret.token }}`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_automations_code_channels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('preset', 40)->nullable();
            $table->jsonb('kinds');                       // ["phone"] / ["email"]
            $table->string('method', 10)->default('POST');
            $table->text('url');
            $table->text('headers')->nullable();
            $table->string('body_format', 10)->default('json');  // json | form | none
            $table->text('body')->nullable();
            $table->text('success_when')->nullable();
            $table->text('invalid_when')->nullable();
            $table->text('unreachable_when')->nullable();
            $table->text('check_url')->nullable();        // a free request that proves the key works
            $table->text('secrets')->nullable();          // encrypted json
            $table->string('prefixes')->nullable();       // "380 48" — countries by dialling code
            $table->unsignedInteger('daily_limit')->default(50);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_automations_code_channels');
    }
};
