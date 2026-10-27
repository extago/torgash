<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An order placed by phone has no email address and no town.
 *
 * Both columns were NOT NULL because every order used to come from a form that
 * asked for them. Buying in one click asks for a phone number and nothing else,
 * and the two ways to keep the columns full are both worse than this: inventing
 * an address (which is exactly what a typical one-click does — and it is why
 * its orders cannot be
 * trusted to email anybody) or storing an empty string, which is a made-up
 * value wearing a disguise. NULL says "not given", and the confirmation email
 * is simply not sent rather than sent nowhere.
 *
 * The full checkout still requires both: PlaceOrder::rules() is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('city')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Rows placed in one click have no email; giving them one to satisfy
        // the constraint would be inventing the very thing this avoids, so
        // they are the shop's to fix before rolling back.
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('city')->nullable(false)->change();
        });
    }
};
