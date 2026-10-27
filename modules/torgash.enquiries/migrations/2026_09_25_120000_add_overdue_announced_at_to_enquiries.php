<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When `enquiries.enquiry.overdue` was last announced for this enquiry — so a
 * wait is announced ONCE, and a customer writing again (which starts a new
 * wait) arms it anew. See Torgash\Enquiries\Overdue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_enquiries_enquiries', function (Blueprint $table) {
            $table->timestamp('overdue_announced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_enquiries_enquiries', function (Blueprint $table) {
            $table->dropColumn('overdue_announced_at');
        });
    }
};
