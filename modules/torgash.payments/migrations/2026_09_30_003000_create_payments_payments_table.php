<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A PAYMENT is a unit of its own, not the order's last message (PLAN §40.10/4).
 *
 * An order sold "200 now, the rest to the courier" whose rest was paid online
 * after all has two invoices at the bank, and so does an order another one was
 * merged into. Read as "the order's latest external id", the second invoice
 * hid the first: a refund or a capture reached only one of them, and the rest
 * arriving wrote the order's paid total as the rest alone — the deposit
 * vanished from it.
 *
 * One row per (gateway, the gateway's id for the payment): what it was asked
 * for, its own state and what it holds NOW in the shop's books. The order's
 * `paid_total` is the prepaid part plus the sum of these (Ledger), and every
 * button on the order screen acts on one of them. `started_as` keeps the id a
 * payment was begun under when the gateway renamed it (a Stripe session
 * becomes a PaymentIntent).
 *
 * Filled from the transaction log for orders paid before this: each id the log
 * knows is a payment, in the state of the last message that was applied to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('m_payments_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('m_checkout_orders')->cascadeOnDelete();
            $table->string('gateway', 64);
            $table->string('external_id')->nullable();
            $table->string('started_as')->nullable();
            $table->string('state', 32)->default('pending');
            $table->bigInteger('requested')->default(0);
            $table->bigInteger('amount')->default(0);
            $table->timestamps();

            $table->unique(['gateway', 'external_id']);
            $table->index(['order_id', 'id']);
        });

        Schema::table('m_payments_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->index('payment_id');
        });

        $groups = DB::table('m_payments_transactions as t')
            ->join('m_checkout_orders as o', 'o.id', '=', 't.order_id')
            ->whereNotNull('t.external_id')
            ->select('t.gateway', 't.order_id', 't.external_id')
            ->selectRaw('min(t.created_at) as created_at, max(t.created_at) as updated_at')
            ->groupBy('t.gateway', 't.order_id', 't.external_id')
            ->orderBy('t.order_id')
            ->get();

        $withMoney = ['partially_paid', 'authorized', 'paid', 'partially_refunded'];

        foreach ($groups as $group) {
            if (DB::table('m_payments_payments')->where('gateway', $group->gateway)->where('external_id', $group->external_id)->exists()) {
                continue; // one id reported against two orders: the first keeps it
            }

            $rows = DB::table('m_payments_transactions')
                ->where('gateway', $group->gateway)->where('order_id', $group->order_id)->where('external_id', $group->external_id)
                ->orderBy('id');

            $first = (clone $rows)->first();
            $last = (clone $rows)->where('applied', true)->orderByDesc('id')->first()
                ?? (clone $rows)->whereNotNull('state')->orderByDesc('id')->first();
            $state = $last->state ?? 'pending';

            $id = DB::table('m_payments_payments')->insertGetId([
                'order_id' => $group->order_id,
                'gateway' => $group->gateway,
                'external_id' => $group->external_id,
                'state' => $state,
                'requested' => (int) ($first->amount ?? 0),
                'amount' => in_array($state, $withMoney, true) && ($last->applied ?? false) ? (int) ($last->amount ?? 0) : 0,
                'created_at' => $group->created_at,
                'updated_at' => $group->updated_at,
            ]);

            DB::table('m_payments_transactions')
                ->where('gateway', $group->gateway)->where('order_id', $group->order_id)->where('external_id', $group->external_id)
                ->update(['payment_id' => $id]);
        }
    }

    public function down(): void
    {
        Schema::table('m_payments_transactions', function (Blueprint $table) {
            $table->dropIndex(['payment_id']);
            $table->dropColumn('payment_id');
        });

        Schema::dropIfExists('m_payments_payments');
    }
};
