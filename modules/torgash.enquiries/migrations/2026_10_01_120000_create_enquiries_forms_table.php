<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forms the shop builds itself (PLAN §48): each one is a KIND of enquiry made
 * of data instead of code, so its answers land in the same inbox with the
 * same statuses, letters, managers and automations as a callback does.
 *
 * `fields` is an ordered jsonb ARRAY — jsonb keeps no key order, and the order
 * of the questions is the form. Every word in it (a label, a hint, an option)
 * is a {locale: text} map, written one language at a time like any content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_enquiries_forms', function (Blueprint $table) {
            $table->id();
            // What `{form:code}` names in a text. The KIND is `form.<id>`, never
            // the code: enquiries keep their kind forever, a code gets renamed.
            $table->string('code')->unique();
            $table->jsonb('name');
            $table->jsonb('intro')->nullable();
            $table->jsonb('fields')->default('[]');
            $table->jsonb('button')->nullable();
            $table->jsonb('success')->nullable();
            $table->boolean('consent')->default(false);
            $table->jsonb('consent_text')->nullable();
            $table->string('consent_url')->nullable();
            // Where this form's letters go instead of the shop's inbox, and who
            // is put in charge of what it brings — both optional.
            $table->string('notify_email')->nullable();
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_enquiries_forms');
    }
};
