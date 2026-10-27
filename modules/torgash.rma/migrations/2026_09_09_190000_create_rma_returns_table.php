<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The machine-readable half of a return.
 *
 * The human half — who asked, why, the conversation, the status, the manager,
 * the notifications — is an ENQUIRY, because a return is a kind of ticket and
 * not a second inbox (§29.2). What an enquiry cannot hold is a list of lines
 * with quantities: its answers are a map of strings with a label snapshot, made
 * for printing at a person, and putting the goods being returned in there would
 * be storing machine data in a display structure. So the ticket carries the
 * conversation and this row carries the act.
 *
 * `state` is its own small vocabulary, and it is not the enquiry's status. The
 * enquiry's says how the CONVERSATION stands (new, in progress, answered); this
 * one says where the goods and the money are, which is what the buttons act on
 * — "approved" and "received" have no meaning at all in the other one.
 *
 * No foreign key to the order: this module may not own checkout's table, and an
 * order deleted years later must not take the record of a refund with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_rma_returns', function (Blueprint $table) {
            $table->id();

            // The ticket. Deleting it deletes this: the return IS that
            // conversation, not a fact that outlives it.
            $table->foreignId('enquiry_id')->constrained('m_enquiries_enquiries')->cascadeOnDelete();

            $table->unsignedBigInteger('order_id')->index();

            // refund | exchange — what the customer asked for, which is not
            // necessarily what the shop ends up doing.
            $table->string('resolution', 16);

            // requested | approved | declined | received | completed
            $table->string('state', 16)->default('requested')->index();

            // What is being sent back: product_id, sku, name, qty, unit_price,
            // line_total — a SNAPSHOT off the order's own lines, frozen the way
            // the order froze them. The catalogue renames and reprices things.
            $table->jsonb('lines');

            // Minor units of the shop's books, like every stored amount. Null
            // until money actually moves; it is not derivable from the lines
            // afterwards, because a shop may refund a different amount than the
            // goods came to (postage kept, a partial agreement).
            $table->integer('refund_amount')->nullable();

            // The order placed to replace the goods, when the answer was an
            // exchange. A new order rather than an edit of the old one (§29.3/3):
            // editing moves a total the customer, 1C and the accounts already
            // agreed on.
            $table->unsignedBigInteger('exchange_order_id')->nullable();

            $table->timestamp('received_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_rma_returns');
    }
};
