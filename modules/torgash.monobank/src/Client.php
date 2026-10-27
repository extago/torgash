<?php

namespace Torgash\Monobank;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The HTTP half of monobank's acquiring API — nothing but the token, the base
 * URL and the two headers they ask for.
 *
 * There is no separate sandbox host: a **test token** (the one issued for a
 * personal account, "токен фізичної особи") points the same endpoints at play
 * money. That is why the binding's `test_mode` flag is a label on the screen
 * rather than a different URL — the shop owner has to be able to see which kind
 * of token is in there, because nothing else will tell them.
 */
class Client
{
    public const BASE = 'https://api.monobank.ua/api/merchant/';

    public const TIMEOUT = 15;

    public function __construct(protected string $token) {}

    /** @param  array<string, mixed>  $data */
    public function post(string $path, array $data): Response
    {
        return $this->request()->post(self::BASE.$path, $data);
    }

    /** @param  array<string, mixed>  $query */
    public function get(string $path, array $query = []): Response
    {
        return $this->request()->get(self::BASE.$path, $query);
    }

    protected function request()
    {
        return Http::withHeaders([
            'X-Token' => $this->token,
            // They ask integrations to identify themselves; it is what puts a
            // name next to the requests in their own support tooling.
            'X-Cms' => 'Torgash',
            'X-Cms-Version' => '1.0.0',
        ])->timeout(self::TIMEOUT)->acceptJson();
    }
}
