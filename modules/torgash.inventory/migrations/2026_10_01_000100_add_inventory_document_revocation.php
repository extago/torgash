<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A posted document REVOKED (`Revoking`): its stock is given back, its
 * movements and batches leave the journal, and the row stays — status
 * `revoked`, who did it, when and why — so the number is never reused and
 * the act itself is on record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_inventory_documents', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->string('revoked_by_name', 190)->nullable();
            $table->string('revoke_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('m_inventory_documents', function (Blueprint $table) {
            $table->dropColumn(['revoked_at', 'revoked_by', 'revoked_by_name', 'revoke_reason']);
        });
    }
};
