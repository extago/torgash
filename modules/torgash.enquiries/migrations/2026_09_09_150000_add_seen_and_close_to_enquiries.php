<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the customer last looked at their own thread.
 *
 * One stamp on the enquiry rather than a read flag per message: the question it
 * answers is "is there an answer here I have not read", and for a conversation
 * with two participants and no partial reading that is the same answer with one
 * column instead of a table. A staff message newer than this stamp is a reply
 * the customer has not seen — which is what the badge in their account counts.
 *
 * NULL means "never opened", which is exactly right for an enquiry left through
 * the form and answered by email.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_enquiries_enquiries', function (Blueprint $table) {
            $table->timestamp('customer_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_enquiries_enquiries', function (Blueprint $table) {
            $table->dropColumn('customer_seen_at');
        });
    }
};
