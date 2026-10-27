<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The settlement the order goes to, as an id in the locations registry.
 *
 * The country column added earlier fixed "Ivanivka is an ambiguous address";
 * this fixes the other half. A town NAME cannot be re-quoted: zones match on a
 * place id, and the carrier looks its own settlement up by it. Without this
 * column a placed order can never be re-priced for delivery — the destination
 * it was quoted for is not recoverable from the text that was stored.
 *
 * Deliberately NOT a foreign key. Checkout does not require torgash.locations
 * and must keep working when it is not installed; a constraint here would make
 * checkout own a table another module may uninstall. Nullable for the same
 * reason, plus the honest one: orders placed before this column existed, and
 * orders from a shop with no registry at all, genuinely have no place id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->unsignedBigInteger('place_id')->nullable()->after('city');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropColumn('place_id');
        });
    }
};
