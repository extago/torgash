<?php

namespace Torgash\Stripe;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Stripe's REST API: a Bearer secret key, form-encoded bodies, JSON answers —
 * and a PINNED API version.
 *
 * Pinned because the shape of every object and every webhook event follows
 * the version, and an account's default version moves when somebody clicks
 * "upgrade" in the dashboard. A request that names its version keeps meaning
 * what this code was written against. (Webhook events follow the version
 * chosen for the ENDPOINT, which is why the setup notes ask for this one.)
 */
class Client
{
    public const API = 'https://api.stripe.com/v1/';

    public const VERSION = '2024-06-20';

    public const TIMEOUT = 20;

    public function __construct(protected string $secretKey) {}

    /** @param  array<string, mixed>  $data */
    public function post(string $path, array $data = [], ?string $idempotencyKey = null): Response
    {
        return $this->request($idempotencyKey)->asForm()->post(self::API.$path, $data);
    }

    /** @param  array<string, mixed>  $query */
    public function get(string $path, array $query = []): Response
    {
        return $this->request()->get(self::API.$path, $query);
    }

    protected function request(?string $idempotencyKey = null)
    {
        return Http::withToken($this->secretKey)
            ->withHeaders(array_filter([
                'Stripe-Version' => self::VERSION,
                // Stripe's own guard against a double-clicked refund: the same
                // key twice is one request.
                'Idempotency-Key' => $idempotencyKey,
            ]))
            ->timeout(self::TIMEOUT)
            ->acceptJson();
    }

    /**
     * Stripe's webhook signature, checked by hand as their documentation
     * describes: `Stripe-Signature: t=…,v1=…[,v1=…]`, HMAC-SHA256 over
     * "{t}.{raw body}" with the endpoint's `whsec_` secret. Every `v1` is a
     * candidate (a rolled secret signs twice for a day); anything else — the
     * `v0` test scheme included — is ignored, which is what stops a downgrade.
     * A signature older than the tolerance is a replay and is refused.
     */
    public static function verifyWebhook(string $payload, string $header, string $secret, int $tolerance = 300, ?int $now = null): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = ctype_digit($value) ? (int) $value : null;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || $signatures === [] || abs(($now ?? time()) - $timestamp) > $tolerance) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
