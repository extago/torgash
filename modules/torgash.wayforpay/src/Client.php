<?php

namespace Torgash\WayForPay;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * WayForPay's API: one URL, a JSON body, and `transactionType` saying what
 * the call is. Every message is signed with HMAC-MD5 under the merchant's
 * secret key, over a DIFFERENT list of fields for each kind of message, joined
 * by `;` — so the list is written out at each call rather than guessed from
 * the body.
 */
class Client
{
    public const API = 'https://api.wayforpay.com/api';

    public const TIMEOUT = 15;

    public function __construct(protected string $account, protected string $secret) {}

    public function account(): string
    {
        return $this->account;
    }

    /** @param  array<string, mixed>  $body */
    public function call(array $body): Response
    {
        return Http::timeout(self::TIMEOUT)
            ->acceptJson()
            ->asJson()
            ->post(self::API, ['merchantAccount' => $this->account, 'apiVersion' => 1] + $body);
    }

    /**
     * The signature over these values, in this order. Arrays (a product list)
     * are spread in place; every value is used exactly as it is sent, which is
     * why amounts are prepared by `amount()` before either happens.
     *
     * @param  list<mixed>  $values
     */
    public function sign(array $values): string
    {
        $flat = [];

        array_walk_recursive($values, function ($value) use (&$flat) {
            $flat[] = (string) $value;
        });

        return hash_hmac('md5', implode(';', $flat), $this->secret);
    }

    public function verify(array $values, string $signature): bool
    {
        return $this->secret !== '' && $signature !== '' && hash_equals($this->sign($values), $signature);
    }

    /**
     * Minor units as the decimal WayForPay takes — and as a value whose string
     * form is the one that goes into the JSON: a whole amount is sent as an
     * integer, because `100.0` is "100" to PHP's string cast and "100.0" to
     * json_encode, and a signature over one of them does not match the other.
     */
    public static function amount(int $minor): int|float
    {
        return $minor % 100 === 0 ? intdiv($minor, 100) : round($minor / 100, 2);
    }
}
