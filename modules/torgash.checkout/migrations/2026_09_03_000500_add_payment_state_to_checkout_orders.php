<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the money arrived — kept apart from `status`, which is about
 * fulfilment.
 *
 * Every shop on an older engine ends up with "Awaiting payment", "Paid" and "Refunded"
 * mixed into the same list as "Packed" and "Shipped", because a gateway there
 * has nowhere else to write: its addons map the acquirer's own statuses onto
 * ORDER statuses (in one monobank add-on the mapping is literally a
 * setting on the payment method). The cost of that is a shop unable to say
 * "paid AND shipped", and unable to ask "what is unpaid" without knowing which
 * of its hand-made statuses mean money.
 *
 * So: two independent axes. `status` stays admin-editable, per shop, and is
 * where the workflow lives. `payment_state` is a fixed vocabulary the engine
 * understands (PaymentState), which is what lets a report, a filter, a trigger
 * or a future gateway agree on what "paid" means.
 *
 * `paid_total` is minor units like every other amount here, and is not simply
 * derivable from the state: a partial refund and an underpayment are both
 * "some, not all", and the order's own total can move afterwards when an admin
 * edits the contents. `paid_at` is when it settled — a refund window is counted
 * from the payment, not from the order (14 days, in the monobank case).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->string('payment_state', 32)->default('pending')->after('status');
            $table->unsignedBigInteger('paid_total')->default(0)->after('payment_state');
            $table->timestamp('paid_at')->nullable()->after('paid_total');

            // "Show me everything unpaid" is the reason this column exists, and
            // it is the one query that runs against the whole table.
            $table->index('payment_state');
        });
    }

    public function down(): void
    {
        Schema::table('m_checkout_orders', function (Blueprint $table) {
            $table->dropIndex(['payment_state']);
            $table->dropColumn(['payment_state', 'paid_total', 'paid_at']);
        });
    }
};
