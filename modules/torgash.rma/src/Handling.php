<?php

namespace Torgash\Rma;

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderLog;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Orders\OrderStatusChanger;
use Torgash\Checkout\Orders\OrderStock;
use Torgash\Checkout\Orders\Refund;
use Torgash\Enquiries\PostMessage;
use Torgash\Rma\Models\ReturnRequest;

/**
 * The one place a return moves — the same law as RequestReturn for creating one,
 * Submit for an enquiry and PlaceOrder for an order.
 *
 * Three callers already exist (the admin card, the API, and an automations
 * action the moment somebody wants one), and each of the acts below is more
 * than a column: receiving goods moves stock, refunding moves the payment state
 * through checkout, and every one of them can carry a word to the customer,
 * which is a message in the ticket rather than an email nobody keeps.
 *
 * What it deliberately does not contain is arithmetic of its own. Stock goes
 * back through checkout's OrderStock, money through checkout's Refund, the
 * order's status through OrderStatusChanger, the replacement order through
 * PlaceOrder. This class decides WHEN, and the shop's own code decides HOW —
 * which is the whole reason returns are a layer rather than a second engine.
 */
class Handling
{
    public function __construct(
        protected OrderStock $stock,
        protected Refund $refunds,
        protected OrderStatusChanger $statuses,
        protected PostMessage $messages,
        protected Exchange $exchange,
    ) {}

    /**
     * Yes, send it back — and the reply that says so.
     *
     * The note is optional but the conversation is not an optional feature: a
     * customer told "approved" by a state badge on a page they may never open
     * has been told nothing. When the manager types something it goes into the
     * ticket, which is what emails them.
     */
    public function approve(ReturnRequest $return, ?User $staff = null, ?string $note = null): ReturnRequest
    {
        $this->moveTo($return, State::APPROVED, $staff, $note);

        return $return;
    }

    /** No. The reason belongs in the conversation, where the customer can read it. */
    public function decline(ReturnRequest $return, ?User $staff = null, ?string $note = null): ReturnRequest
    {
        $this->moveTo($return, State::DECLINED, $staff, $note);

        return $return;
    }

    /**
     * The parcel is here — which is when stock moves, not when the return was
     * approved. An approval is a promise; a delivery is a fact.
     *
     * The disposition is a DECISION, not a setting, because of the case the
     * owner named: a warranty item goes back to the manufacturer or into the
     * bin, and adding it to stock would put a broken thing on sale. The setting
     * only chooses which radio is selected when the form opens.
     *
     * @param  string  $disposition  restock | writeoff
     * @param  int|null  $warehouseId  where it actually arrived; null means
     *                                 "back where it came from", read off the order's frozen breakdown.
     */
    public function receive(
        ReturnRequest $return,
        string $disposition = ReturnRequest::RESTOCK,
        ?int $warehouseId = null,
        ?User $staff = null,
        ?string $note = null,
    ): ReturnRequest {
        if ($return->received_at) {
            throw ValidationException::withMessages([
                'state' => __('These goods have already been received.'),
            ]);
        }

        $order = $return->order;

        if ($disposition === ReturnRequest::RESTOCK && $order) {
            // Checkout's own code, so the units go back to the warehouses that
            // gave them up — or to the one named, when the parcel arrived
            // somewhere else. A no-op when the order never took them out, which
            // is exactly right: nothing was owed.
            app(\Torgash\Catalog\Stock\StockMoves::class)->because(
                ['type' => 'return', 'order_id' => $order->id, 'order_number' => $order->order_number, 'return_id' => $return->id],
                fn () => $this->stock->restore($order, $return->lines, $warehouseId),
            );
        }

        $return->forceFill([
            'disposition' => $disposition,
            'warehouse_id' => $disposition === ReturnRequest::RESTOCK ? $warehouseId : null,
            'received_at' => now(),
        ]);

        $this->moveTo($return, State::RECEIVED, $staff, $note);

        $disposition === ReturnRequest::RESTOCK
            ? $this->log($return, 'Return #:id received, :n line(s) back into stock', ['id' => $return->id, 'n' => count($return->lines)])
            : $this->log($return, 'Return #:id received and written off', ['id' => $return->id]);

        return $return;
    }

    /**
     * Give the money back. What that MEANS is checkout's answer, not ours: a
     * gateway is asked where there is one, a hold is released rather than
     * transferred, and a shop that settles in its own accounting is told so
     * plainly instead of having a refund invented for it.
     *
     * A refund completes the return: the goods are here and the money has gone.
     */
    public function refund(ReturnRequest $return, int $amount, ?User $staff = null, ?string $note = null): string
    {
        $order = $return->order;

        if (! $order) {
            throw ValidationException::withMessages([
                'amount' => __('The order this return is about no longer exists.'),
            ]);
        }

        $result = $this->refunds->refund($order, $amount, __('Return #:id', ['id' => $return->id]));

        if (! $result->done) {
            throw ValidationException::withMessages(['amount' => $result->summary]);
        }

        $return->forceFill(['refund_amount' => $amount]);

        $this->complete($return, $staff, $note);

        return $result->summary;
    }

    /**
     * The replacement. A NEW order, linked to the old one and to the ticket —
     * never an edit of the original, whose total the customer, 1C and the
     * accounts have all already agreed on (§29.3/3).
     *
     * @param  string  $surcharge  none | due | owed — what is left to settle in
     *                             money, decided by a person. The engine records the answer and invents
     *                             no payment: an exchange at today's prices need not come to what was
     *                             paid last month, and only the shop knows whether it will chase the
     *                             difference.
     */
    public function exchange(ReturnRequest $return, string $surcharge = ReturnRequest::SURCHARGE_NONE, ?User $staff = null, ?string $note = null): Order
    {
        if ($return->exchange_order_id) {
            throw ValidationException::withMessages([
                'state' => __('An exchange order has already been created for this return.'),
            ]);
        }

        $order = $this->exchange->place($return);

        $return->forceFill([
            'exchange_order_id' => $order->id,
            'surcharge' => $surcharge,
        ]);

        $this->complete($return, $staff, $note);

        $this->log($return, 'Exchange order :number created for return #:id', ['number' => $order->order_number, 'id' => $return->id]);

        return $order;
    }

    /**
     * The end of it, and the one place the ORDER's own status is touched.
     *
     * Only when the return covers everything that was still returnable: half an
     * order coming back does not make the order returned, and a shop that moved
     * it anyway would be telling 1C and the customer that a parcel they still
     * have was sent back. The status itself is the shop's own, chosen in
     * settings, moved through OrderStatusChanger and never by writing a column.
     */
    protected function complete(ReturnRequest $return, ?User $staff, ?string $note): void
    {
        $return->forceFill(['completed_at' => now()]);
        $this->moveTo($return, State::COMPLETED, $staff, $note);

        $code = (string) setting('torgash.rma', 'completed_status', '');
        $order = $return->order;

        if ($code === '' || ! $order || Eligibility::remaining($order) !== []) {
            return;
        }

        $status = OrderStatus::find($code);

        // Asked again here and not only in the settings picker: the status was
        // chosen months ago and may have been edited into one that puts stock
        // back since. Moving the order into it now would credit the whole order
        // on top of the lines this return already credited.
        if ($status && $status->inventory_action !== 'increase') {
            $this->statuses->change($order, $status);
        }
    }

    /**
     * Save the state and, when the manager wrote something, say it in the
     * ticket. In that order: a message announces a hook, and a listener reading
     * a return that had not been saved yet would read the old state.
     */
    protected function moveTo(ReturnRequest $return, string $state, ?User $staff, ?string $note): void
    {
        $return->state = $state;
        $return->save();

        if ($staff && is_string($note) && trim($note) !== '') {
            $this->messages->fromStaff($return->enquiry, $staff, trim($note));
        }
    }

    /**
     * On the ORDER's log, not a log of this module's own: somebody looking at
     * the order a year from now is asking "why did the stock move", and the
     * answer has to be where they are looking.
     */
    protected function log(ReturnRequest $return, string $key, array $params = []): void
    {
        if (! $return->order_id) {
            return;
        }

        OrderLog::record($return->order_id, $key, $params);
    }
}
