<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the conversation was ended for good.
 *
 * A SECOND AXIS rather than a fifth status, the same shape as an order's
 * payment_state beside its status: "have we dealt with this" and "may anybody
 * still write here" are different questions, and a shop can want either answer
 * with any value of the other. A resolved question is locked; a rejected one is
 * locked without ever having been answered; a thread can be answered and left
 * open on purpose.
 *
 * What it buys is the thing statuses cannot: an enquiry a customer cannot
 * resurrect a year later, on top of an old context nobody remembers. Reopening
 * is a deliberate act by the shop, not a side effect of somebody typing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_enquiries_enquiries', function (Blueprint $table) {
            $table->timestamp('locked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_enquiries_enquiries', function (Blueprint $table) {
            $table->dropColumn('locked_at');
        });
    }
};
