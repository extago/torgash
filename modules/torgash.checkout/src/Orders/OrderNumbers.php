<?php

namespace Torgash\Checkout\Orders;

use Illuminate\Support\Facades\DB;

/**
 * What an order is called — `order_number`, the one thing about an order
 * the customer reads out on the phone.
 *
 * The number is drawn from a Postgres sequence of its own
 * (m_checkout_order_numbers), never from the row's id: the id counts from
 * 1 whatever the shop wants, while a shop moving in wants its numbers to
 * carry on from where the old shop stopped, or simply to look as if it had
 * been trading for a while. "Next order number" in the settings moves the
 * sequence forward (`startFrom`, refused below the last number issued, so
 * two orders can never share one — `order_number` is unique). A sequence
 * is handed out outside transactions, so an order that rolls back leaves a
 * gap; a gap is what sequential numbering looks like and nothing reads one.
 *
 * How it is written is two settings: a prefix ("TG-", or none) and how
 * many digits to pad to (6: 000123; 0: as it is). Only new orders: a
 * number already given is on letters and invoices and stays.
 *
 * A number is never what authorises reading an order (OrderKey): it is
 * sequential by design, and that is fine.
 */
class OrderNumbers
{
    public const SEQUENCE = 'm_checkout_order_numbers';

    /** The next order's number, taken: the sequence moves on. */
    public function next(): string
    {
        return $this->format((int) DB::selectOne('SELECT nextval(?) AS n', [self::SEQUENCE])->n);
    }

    public function format(int $number): string
    {
        $digits = max(0, min(12, (int) setting('torgash.checkout', 'order_number_digits')));

        return (string) setting('torgash.checkout', 'order_number_prefix').str_pad((string) $number, $digits, '0', STR_PAD_LEFT);
    }

    /**
     * The last number handed out, null before the first. Until the sequence
     * has been asked once, the orders already there were numbered by their
     * id (the shop before this sequence), so the highest id is the last.
     */
    public function last(): ?int
    {
        $row = DB::selectOne('SELECT last_value, is_called FROM '.self::SEQUENCE);

        if ($row->is_called) {
            return (int) $row->last_value;
        }

        $highest = DB::table('m_checkout_orders')->max('id');

        return $highest === null ? null : (int) $highest;
    }

    /** Why the sequence may not be moved to $number, or null when it may. */
    public function refusal(int $number): ?string
    {
        if ($number < 1) {
            return __('The next order number must be 1 or more.');
        }

        if (($last = $this->last()) !== null && $number <= $last) {
            return __('The last number issued is :last — the next one can only be above it.', ['last' => $last]);
        }

        return null;
    }

    /** The next order gets exactly $number; the ones after count on from it. */
    public function startFrom(int $number): void
    {
        if ($this->refusal($number) === null) {
            DB::statement('SELECT setval(?, ?, false)', [self::SEQUENCE, $number]);
        }
    }
}
