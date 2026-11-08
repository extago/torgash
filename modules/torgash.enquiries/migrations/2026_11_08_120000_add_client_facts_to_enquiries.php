<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an enquiry was sent from (ClientFacts): the address, searched and
 * counted, so a column of its own, and the rest in a bag — the list is the
 * shop's choice and a module's to extend. 45 characters is the longest
 * spelling of an IPv6 address; NULL on every enquiry sent before this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_enquiries_enquiries', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->index();
            $table->jsonb('client')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_enquiries_enquiries', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'client']);
        });
    }
};
