<?php

namespace Torgash\LiqPay;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * LiqPay's envelope: every message either way is `data` (base64 of a JSON
 * object) plus `signature` = base64(sha1(private_key . data . private_key)),
 * raw sha1 bytes before the base64.
 *
 * The public key travels INSIDE the signed data, and the private one never
 * travels at all — which is why the callback URL carries nothing secret and
 * nothing that names an order.
 */
class Client
{
    public const CHECKOUT = 'https://www.liqpay.ua/api/3/checkout';

    public const REQUEST = 'https://www.liqpay.ua/api/request';

    public const TIMEOUT = 15;

    public function __construct(protected string $publicKey, protected string $privateKey) {}

    /**
     * The page to send a customer to. Built locally, with no round trip: the
     * checkout takes the envelope as query parameters as readily as a POSTed
     * form, and a link can be followed by a redirect where a form would need a
     * page of its own that submits itself.
     *
     * @param  array<string, mixed>  $params
     */
    public function checkoutUrl(array $params): string
    {
        [$data, $signature] = $this->envelope($params);

        return self::CHECKOUT.'?'.http_build_query(['data' => $data, 'signature' => $signature]);
    }

    /**
     * A server-to-server call (`status`, `refund`, `hold_completion`). The
     * answer is plain JSON, not an envelope.
     *
     * @param  array<string, mixed>  $params
     */
    public function request(array $params): Response
    {
        [$data, $signature] = $this->envelope($params);

        return Http::asForm()
            ->timeout(self::TIMEOUT)
            ->acceptJson()
            ->post(self::REQUEST, ['data' => $data, 'signature' => $signature]);
    }

    /** Is this `data` really signed with our private key? */
    public function verify(string $data, string $signature): bool
    {
        return $this->privateKey !== ''
            && $data !== ''
            && hash_equals($this->sign($data), $signature);
    }

    /** @return array<string, mixed>|null */
    public static function decode(string $data): ?array
    {
        $json = json_decode((string) base64_decode($data, true), true);

        return is_array($json) ? $json : null;
    }

    public function sign(string $data): string
    {
        return base64_encode(sha1($this->privateKey.$data.$this->privateKey, true));
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{0: string, 1: string}
     */
    protected function envelope(array $params): array
    {
        $data = base64_encode(json_encode(
            ['version' => 3, 'public_key' => $this->publicKey] + $params,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        ));

        return [$data, $this->sign($data)];
    }
}
