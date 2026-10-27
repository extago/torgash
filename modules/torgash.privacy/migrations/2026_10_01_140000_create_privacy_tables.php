<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Personal data (PLAN §49): the record of consents and the requests to
 * download or erase one's data.
 *
 * A request keeps the person's name and contacts as written, because erasing
 * them deletes the account the row pointed at — and "done on 3 October, for
 * whom" is exactly what a shop must still be able to show afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_privacy_consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // cookies | enquiry_form | … — whoever announced it says.
            $table->string('kind', 64)->index();
            $table->jsonb('categories')->nullable();
            $table->text('text')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            // Kept as proof, for as long as the retention setting says.
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });

        Schema::create('m_privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 16);            // export | erase
            $table->string('status', 16)->default('new')->index(); // new | done | rejected
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone', 40)->nullable();
            $table->text('note')->nullable();
            // What each owner did when it was carried out — the answer to "what
            // exactly did you delete".
            $table->jsonb('report')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_privacy_requests');
        Schema::dropIfExists('m_privacy_consents');
    }
};
