<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Nullable: guest checkout keeps working unauthenticated. Set when the
// customer is logged in at checkout time — see CheckoutController::store().
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('storefront_id')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
