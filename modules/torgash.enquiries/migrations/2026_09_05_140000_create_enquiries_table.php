<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One table for every kind of "somebody asked us something".
 *
 * A callback request, a question about a product, "tell me when it is back in
 * stock", "send me a quote" and a contact form are the same row: who asked, how
 * to reach them, what about, what they wrote, and a small state. The difference
 * between them is `kind` — data, not code — because a shop that answers all of
 * them needs ONE inbox to look into, and a table per reason gives it none.
 *
 * Modelled on the usual call-request table,
 * with two changes it earns:
 *
 * - the ANSWERS are stored, always, together with a snapshot of what the fields
 *   were called at the time. A typical form builder stores nothing at all —
 *   it composes an email and sends it — so a bounced message is an
 *   enquiry that never existed. And a label snapshot is what lets a form be
 *   edited later without rewriting the past, the same reason an order keeps
 *   payment_method_name and its own tax rows.
 * - no order_id, because buying in one click is an ORDER and goes through
 *   PlaceOrder. Older engines put it here and had to invent an email address for the
 *   buyer to make it fit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_enquiries_enquiries', function (Blueprint $table) {
            $table->id();

            // callback | product_question | back_in_stock | message — see Kinds.
            $table->string('kind')->index();

            // new | in_progress | done | rejected. A fixed vocabulary rather
            // than the shop's own table: order statuses are configurable
            // because fulfilment differs from shop to shop, but "have we rung
            // them back yet" is a state, not a process.
            $table->string('status')->default('new')->index();

            $table->string('name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // What the enquiry is ABOUT, when it is about anything: product,
            // order, category, page. No foreign key on purpose — this module
            // may not own another's table, and a product deleted next year
            // must not delete the question somebody asked about it.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // The answers, plus how the fields were labelled when they were
            // given. {"values": {...}, "labels": {...}}
            $table->jsonb('fields')->nullable();

            // What was in the basket at that moment: name, quantity and price
            // per line, frozen. The manager's first question is "what were they
            // looking at", and nothing else can answer it afterwards.
            $table->jsonb('cart')->nullable();

            // Who they are, as the rest of the shop names a person with no
            // account (Checkout\Orders\CustomerRef) — so an enquiry and an
            // order from the same phone are one counterparty to any CRM.
            $table->string('customer_ref')->nullable()->index();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Which shop, in which language it was written, and where from.
            $table->foreignId('storefront_id')->nullable()->constrained('storefronts')->nullOnDelete();
            $table->string('locale', 10)->nullable();
            $table->string('page_url')->nullable();

            // Who is dealing with it, and what they wrote down. Staff-only.
            $table->foreignId('manager_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();

            $table->timestamp('answered_at')->nullable();
            $table->timestamps();

            // The inbox is read newest-first and filtered by state.
            $table->index(['status', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_enquiries_enquiries');
    }
};
