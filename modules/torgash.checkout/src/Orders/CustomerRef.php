<?php

namespace Torgash\Checkout\Orders;

use Illuminate\Support\Str;
use Torgash\Checkout\Models\Order;

/**
 * A short, stable name for the person who placed an order.
 *
 * Two orders from the same buyer must carry the same ref, or every system that
 * is handed them — 1C first, a CRM next — records a new customer each time. The
 * shop already knows this for a registered account; what it has never had is an
 * answer for a guest, and buying in one click will make the guest the normal
 * case rather than the exception.
 *
 * DERIVED, not looked up. The ref is a hash of the strongest identity the order
 * carries, so the same buyer lands on the same string without a query, without
 * an index, and — the part a lookup could not do — consistently with orders
 * placed before this class existed, whenever they get exported.
 *
 * The order of preference is the order of how firmly each thing identifies a
 * person, and the trade-off is deliberate: a customer who ordered as a guest
 * and then registered gets a new ref, because an account is a stronger claim
 * about identity than a phone number, and treating the phone as primary would
 * merge a family that shares one.
 *
 * Salted with the app key so a ref means nothing outside this shop. A phone
 * number is drawn from a small enough space to be found by brute force from an
 * unsalted hash, and a ref will end up in other people's systems.
 */
class CustomerRef
{
    /** Long enough that two buyers colliding is not a thing that happens, short enough to read out. */
    protected const LENGTH = 10;

    public function for(Order|array $order): string
    {
        $get = fn (string $key) => $order instanceof Order ? $order->{$key} : ($order[$key] ?? null);

        if ($id = $get('user_id')) {
            return $this->hash('user', (string) $id);
        }

        if ($phone = $this->normalisePhone((string) $get('phone'))) {
            return $this->hash('phone', $phone);
        }

        if ($email = trim((string) $get('email'))) {
            return $this->hash('email', Str::lower($email));
        }

        // Nothing to recognise them by. A random ref still names this order's
        // counterparty, which is all 1C needs; it simply will not merge with
        // anything, and there is nothing here that could honestly merge it.
        return Str::lower(Str::random(self::LENGTH));
    }

    /**
     * Digits only, and only the last nine of them.
     *
     * The same person writes +380 67 123-45-67 one day and 0671234567 the next,
     * and those must be one customer. Nine digits is what survives every way a
     * country code and a trunk prefix get typed, and is still long enough that
     * two real subscribers do not share it.
     */
    protected function normalisePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) >= 9 ? substr($digits, -9) : null;
    }

    protected function hash(string $kind, string $value): string
    {
        return substr(hash_hmac('sha256', $kind.':'.$value, (string) config('app.key')), 0, self::LENGTH);
    }
}
