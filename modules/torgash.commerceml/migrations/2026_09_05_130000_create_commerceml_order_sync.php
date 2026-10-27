<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What 1C has already been told about each order.
 *
 * An order is not sent once and forgotten: it is sent when it first qualifies,
 * and again whenever anything 1C cares about changes — a line edited, an
 * address corrected, the payment settled. The alternative, a flag meaning
 * "exported", makes the exchange a one-shot and leaves 1C holding a copy that
 * was true last Tuesday.
 *
 * So what is stored is a HASH of what was sent. An order whose hash matches the
 * confirmed one is not offered again, and any change to what would be exported
 * produces a different hash, without anybody maintaining a list of the fields
 * that count. It also solves the retry: 1C asks for the orders, then says
 * `success` only when it has actually taken them, and an exchange that dies in
 * between simply offers the same orders next time.
 *
 * No foreign key to the orders table, deliberately — the same rule as
 * checkout's own `place_id`: this module may not make itself un-uninstallable
 * from the other side, and a row whose order is gone is a row that answers no
 * questions and is cleaned up when it is next read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_commerceml_order_sync', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('order_id')->unique();

            // sha1 of the exported document for this order. Two columns, not
            // one: `offered` is what 1C was handed in the last `query`,
            // `confirmed` is what it said it took. Only the second stops the
            // order being offered again.
            $table->string('offered_hash', 40)->nullable();
            $table->string('confirmed_hash', 40)->nullable();
            $table->timestamp('offered_at')->nullable();
            $table->timestamp('confirmed_at')->nullable();

            // The last thing 1C said BACK about this order, for the screen: a
            // shop asking "why is this still Processing" wants to see whether
            // 1C has ever answered at all.
            $table->timestamp('answered_at')->nullable();
            $table->string('answer', 255)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('m_commerceml_order_sync');
    }
};
