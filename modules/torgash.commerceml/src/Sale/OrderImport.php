<?php

namespace Torgash\CommerceMl\Sale;

use Torgash\Checkout\Models\Order;
use Torgash\Checkout\Models\OrderStatus;
use Torgash\Checkout\Orders\OrderStatusChanger;
use Torgash\Checkout\Orders\PaymentState;
use Torgash\Checkout\Orders\RecordPayment;
use Torgash\CommerceMl\Import\FieldMap;
use Torgash\CommerceMl\Models\OrderSync;
use Torgash\CommerceMl\Xml\Node;
use Torgash\CommerceMl\Xml\Tags;

/**
 * What 1C sends back about an order, and the very short list of what that may
 * change.
 *
 * It may move the STATUS, and it may say the money arrived. Nothing else. 1C
 * legitimately holds a document with different lines on it — a warehouse ships
 * nine of the ten, an accountant corrects a price — and importing that would
 * mean a nightly exchange silently changing the total of an order the customer
 * has already agreed to and, quite possibly, already paid. A shop that wants
 * more than a status move has torgash.automations, where the rule is written
 * down, visible and logged.
 *
 * Two facts, then, and each is read the same way the catalogue's weights are:
 * by NAME, from a list the shop types, because no two 1C installations spell
 * their attributes alike. The shipment date is what moves the status — a date
 * being present is the event, its value is only for the log — and the payment
 * date settles the order through checkout's own RecordPayment, the same code
 * the manager's "mark as paid" runs.
 */
class OrderImport
{
    public function __construct(
        protected FieldMap $fields,
        protected OrderStatusChanger $statuses,
        protected RecordPayment $payments,
    ) {}

    /** @return 'created'|'updated'|'skipped' the counters the exchange log keeps */
    public function run(Node $document): string
    {
        $order = $this->find($document);

        if (! $order) {
            return 'skipped';
        }

        $shipped = $this->flag($this->fields->pick($document, 'attr_shipped'));
        $paid = $this->flag($this->fields->pick($document, 'attr_paid'));
        $moved = false;

        if ($shipped !== null) {
            $moved = $this->ship($order);
        }

        if ($paid !== null && $order->payment_state !== PaymentState::PAID) {
            $this->payments->record($order, PaymentState::PAID, null, '1C');
            $moved = true;
        }

        $this->note($order, $document, $shipped, $paid);

        return $moved ? 'updated' : 'skipped';
    }

    /**
     * The order this document is about.
     *
     * `Ид` first and the number second, which is the convention everywhere here
     * (§58): the identifier is what the other system calls this row, and the
     * order number is the shop's own and the shop can renumber. An order matched
     * by its number ACQUIRES the identifier on the way past — that is how a shop
     * whose orders predate the exchange gets linked, one document at a time.
     */
    protected function find(Node $document): ?Order
    {
        $id = $document->text('id');
        $number = $document->text('number');

        if ($id && $order = Order::where('external_id', $id)->first()) {
            return $order;
        }

        $order = collect([$id, $number])->filter()
            ->map(fn (string $key) => Order::where('order_number', $key)->first())
            ->filter()->first();

        if ($order && $id && ! $order->external_id) {
            // Quietly, and only when the column is empty: overwriting an
            // identifier some other system wrote would break its links instead.
            $order->forceFill(['external_id' => $id])->save();
        }

        return $order;
    }

    /**
     * Move it to the status the shop nominated for "1C has shipped this".
     *
     * Through OrderStatusChanger, never a column write: the status carries the
     * warehouse with it, and an order that reached "shipped" without its stock
     * moving is a shop counting goods it has already sent.
     */
    protected function ship(Order $order): bool
    {
        $code = (string) setting('torgash.commerceml', 'sale_status_shipped', 'shipped');
        $status = $code === '' ? null : OrderStatus::find($code);

        if (! $status || $order->status === $status->code) {
            return false;
        }

        $this->statuses->change($order, $status);

        return true;
    }

    /**
     * What 1C last said about this order, for the screen.
     *
     * Written whether or not anything moved: "1C answered, and it said nothing
     * had shipped" is the answer a shop wondering why an order is still open
     * actually needs, and it is invisible if only the changes are recorded.
     */
    protected function note(Order $order, Node $document, ?string $shipped, ?string $paid): void
    {
        $said = array_filter([
            $document->attributes()[Tags::of('order_status')] ?? null,
            $shipped ? __('shipped :date', ['date' => $shipped]) : null,
            $paid ? __('paid :date', ['date' => $paid]) : null,
        ]);

        OrderSync::updateOrCreate(['order_id' => $order->id], [
            'answered_at' => now(),
            'answer' => mb_substr(implode(' · ', $said) ?: __('nothing new'), 0, 255),
        ]);
    }

    /**
     * An attribute that is there and does not say "no".
     *
     * 1C writes these three ways — a date, a "Да", or an empty element that is
     * present on every document whether or not anything happened. Treating a
     * present-but-empty attribute as an event would mark every order shipped on
     * the first exchange.
     */
    protected function flag(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || in_array(mb_strtolower($value), ['нет', 'no', 'false', '0'], true)) {
            return null;
        }

        return $value;
    }
}
