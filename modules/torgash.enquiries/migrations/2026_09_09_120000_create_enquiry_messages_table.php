<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation on an enquiry — the half that turns it into a ticket.
 *
 * What is deliberately NOT here is the first message. The enquiry already
 * stores what the person typed, field by field and with the labels they were
 * asked under, and copying that into a row here would make two sources of truth
 * about the same words: the one the manager reads and the one an export reads,
 * drifting apart the first time somebody edits the row. So the enquiry itself
 * is the opening of the thread and this table holds every turn after it.
 *
 * `is_staff` rather than a table of the shop's answers beside a table of the
 * customer's — the same choice comments made, for the same reason: "show me
 * this conversation" must never become a union ordered by two clocks.
 *
 * The author's NAME is stored beside `user_id`, not derived from it. A staff
 * account can be renamed or deleted, and a reply from March must keep reading
 * as the person who wrote it — the reason an order keeps payment_method_name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_enquiries_messages', function (Blueprint $table) {
            $table->id();

            // Deleting an enquiry deletes its conversation: the thread is a
            // part of that row, not a fact that outlives it.
            $table->foreignId('enquiry_id')->constrained('m_enquiries_enquiries')->cascadeOnDelete();

            // Which side wrote it. A boolean and not an author string, because
            // there are exactly two sides here and there never will be a third:
            // an automation writing into the thread is the shop writing.
            $table->boolean('is_staff')->default(false);

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Nullable: a guest's enquiry may not have given a name at all, and
            // an invented one ("Customer") would be a lie stored as data. The
            // model falls back to the enquiry's own name, then to a label.
            $table->string('author_name')->nullable();

            $table->text('body');

            $table->timestamps();

            // The thread is always read whole, in the order it happened.
            $table->index(['enquiry_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_enquiries_messages');
    }
};
