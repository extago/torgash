<?php

namespace Torgash\Cdek\Api;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * A thin wrapper over CDEK's API v2 (https://apidoc.cdek.ru).
 *
 * Unlike Nova Poshta's one endpoint this is REST: GET for the directories,
 * POST for the calculator, and every call carries a bearer token obtained
 * with the account and secure password (OAuth client_credentials). The token
 * lives an hour; it is cached for slightly less, per account, so a checkout
 * quote costs one call rather than two.
 *
 * Errors come in two shapes, both measured against the test server:
 * `{"errors": [{code, message}]}` from the calculator, and
 * `{"requests": [{"errors": [...]}]}` from everything that goes through the
 * order pipeline, a refused token included.
 */
class Client
{
    public const LIVE = 'https://api.cdek.ru/v2/';

    public const TEST = 'https://api.edu.cdek.ru/v2/';

    /**
     * CDEK's own published test account (apidoc.cdek.ru, "Тестовая среда"):
     * what the test environment uses when the shop has typed nothing, so the
     * module can be tried before a contract exists.
     */
    public const TEST_ACCOUNT = 'wqGwiQx0gg8mLtiEKsUinjVSICCjtTEP';

    public const TEST_SECURE = 'RmAmgvSgSl1yirlz9QupbzOJVqhCxcP5';

    /**
     * How many times a page of a bulk listing is asked for before giving up.
     * Measured: paging the 140 000 cities, one page in thirty came back as an
     * empty body once and fine the second time.
     */
    public const RETRIES = 3;

    protected int $retryPause = 2;

    public function __construct(
        protected ?string $account = null,
        protected ?string $secure = null,
        protected ?bool $test = null,
        protected int $timeout = 30,
    ) {}

    /**
     * Is there anything to talk to CDEK with? The test environment always has
     * an account (its public one); the real one needs both halves.
     */
    public static function configured(): bool
    {
        return (bool) setting('torgash.cdek', 'test_mode', false)
            || (trim((string) setting('torgash.cdek', 'account', '')) !== ''
                && trim((string) setting('torgash.cdek', 'secure_password', '')) !== '');
    }

    /** A client that answers in time for a checkout quote, or not at all. */
    public function quick(int $seconds = 8): static
    {
        $clone = clone $this;
        $clone->timeout = $seconds;

        return $clone;
    }

    public function withoutRetryPause(): static
    {
        $clone = clone $this;
        $clone->retryPause = 0;

        return $clone;
    }

    public function test(): bool
    {
        return $this->test ?? (bool) setting('torgash.cdek', 'test_mode', false);
    }

    public function base(): string
    {
        return $this->test() ? self::TEST : self::LIVE;
    }

    /** @return array{0: string, 1: string} */
    protected function credentials(): array
    {
        $account = trim((string) ($this->account ?? setting('torgash.cdek', 'account', '')));
        $secure = trim((string) ($this->secure ?? setting('torgash.cdek', 'secure_password', '')));

        if ($this->test() && $account === '' && $secure === '') {
            return [self::TEST_ACCOUNT, self::TEST_SECURE];
        }

        return [$account, $secure];
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int|string, mixed>
     *
     * @throws ApiException
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<int|string, mixed>
     *
     * @throws ApiException
     */
    public function post(string $path, array $body = []): array
    {
        return $this->send('POST', $path, $body);
    }

    /**
     * @return array<int|string, mixed>
     *
     * @throws ApiException
     */
    public function delete(string $path): array
    {
        return $this->send('DELETE', $path, []);
    }

    /**
     * The bytes behind a URL CDEK handed out — a printed form's `url`, which
     * wants the same bearer token as everything else.
     *
     * @throws ApiException
     */
    public function download(string $url): string
    {
        $response = Http::withToken($this->token())->timeout($this->timeout)->get($url);

        if ($response->status() === 401) {
            $this->forgetToken();
            $response = Http::withToken($this->token())->timeout($this->timeout)->get($url);
        }

        if ($response->failed()) {
            throw new ApiException("CDEK {$url}: HTTP {$response->status()}", transient: $response->serverError());
        }

        return $response->body();
    }

    /**
     * A listing that pages itself: `page` from 0, `size` per page, until a page
     * comes back shorter than `size`. Each page is retried a few times before
     * the whole listing gives up — a half-pulled directory is what a single
     * flaky page would otherwise leave behind.
     *
     * @param  array<string, mixed>  $query
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws ApiException
     */
    public function pages(string $path, array $query = [], int $size = 1000): \Generator
    {
        $page = 0;

        do {
            $rows = $this->retrying(fn () => $this->get($path, $query + ['size' => $size, 'page' => $page]));
            $rows = array_values(array_filter($rows, 'is_array'));

            yield from $rows;

            $page++;
        } while (count($rows) === $size);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    protected function retrying(callable $call): mixed
    {
        for ($try = 1; ; $try++) {
            try {
                return $call();
            } catch (ApiException $e) {
                // A refused token or a rejected parameter is not going to
                // change on the second asking; an empty body or a 5xx might.
                if ($try >= self::RETRIES || ! $e->transient) {
                    throw $e;
                }

                $this->retryPause && sleep($this->retryPause * $try);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int|string, mixed>
     */
    protected function send(string $method, string $path, array $data, bool $retried = false): array
    {
        $request = Http::withToken($this->token())
            ->timeout($this->timeout)
            ->acceptJson();

        $url = $this->base().ltrim($path, '/');

        try {
            $response = match ($method) {
                'GET' => $request->get($url, $data),
                'DELETE' => $request->delete($url),
                default => $request->asJson()->post($url, $data),
            };
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new ApiException("CDEK {$path}: {$e->getMessage()}", transient: true);
        }

        // A token can be revoked before its hour is up (a new password issued
        // in the cabinet): forget it and ask once more, never in a loop.
        if ($response->status() === 401 && ! $retried) {
            $this->forgetToken();

            return $this->send($method, $path, $data, true);
        }

        return $this->decode($response, $path);
    }

    /** @return array<int|string, mixed> */
    protected function decode(Response $response, string $path): array
    {
        $body = $response->json();

        if ($response->failed() || ! is_array($body)) {
            [$message, $codes] = is_array($body) ? $this->errors($body) : ['', []];

            throw new ApiException(
                $message !== '' ? $message : "CDEK {$path}: HTTP {$response->status()}",
                $codes,
                transient: $response->serverError() || ! is_array($body),
            );
        }

        // A 200 can still carry a refusal — the calculator answers an
        // unknown city with `errors` and nothing else. An answer that DOES
        // carry an `entity` (an order, a print form) is data even when its
        // `requests` hold errors: an order refused after it was accepted
        // says so there, and the waybill has to read it, not crash on it.
        [$message, $codes] = $this->errors($body);

        if ($message !== '' && ! array_is_list($body) && ! isset($body['delivery_sum']) && ! isset($body['tariff_codes']) && ! isset($body['entity'])) {
            throw new ApiException($message, $codes);
        }

        return $body;
    }

    /**
     * @param  array<int|string, mixed>  $body
     * @return array{0: string, 1: list<string>}
     */
    protected function errors(array $body): array
    {
        $errors = (array) ($body['errors'] ?? []);

        foreach ((array) ($body['requests'] ?? []) as $request) {
            $errors = [...$errors, ...(array) ($request['errors'] ?? [])];
        }

        $errors = array_values(array_filter($errors, 'is_array'));

        return [
            implode('; ', array_filter(array_map(fn ($error) => (string) ($error['message'] ?? ''), $errors))),
            array_values(array_filter(array_map(fn ($error) => (string) ($error['code'] ?? ''), $errors))),
        ];
    }

    /**
     * The bearer token for this account, from the cache or freshly issued.
     *
     * Keyed by the account and the environment, so switching the test toggle
     * or pasting a new account never reuses the other one's token.
     *
     * @throws ApiException
     */
    public function token(): string
    {
        $key = $this->tokenKey();
        $cached = Cache::get($key);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        [$account, $secure] = $this->credentials();

        if ($account === '' || $secure === '') {
            throw new ApiException('CDEK: the account and secure password are not set.');
        }

        try {
            $response = Http::asForm()
                ->timeout($this->timeout)
                ->acceptJson()
                ->post($this->base().'oauth/token', [
                    'grant_type' => 'client_credentials',
                    'client_id' => $account,
                    'client_secret' => $secure,
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new ApiException("CDEK oauth/token: {$e->getMessage()}", transient: true);
        }

        $token = (string) ($response->json('access_token') ?? '');

        if ($response->failed() || $token === '') {
            [$message, $codes] = is_array($response->json()) ? $this->errors($response->json()) : ['', []];

            throw new ApiException(
                $message !== '' ? $message : (string) ($response->json('error_description') ?? "CDEK oauth/token: HTTP {$response->status()}"),
                $codes,
                transient: $response->serverError(),
            );
        }

        // A minute short of what CDEK says, so a token is never sent in the
        // second it expires.
        $ttl = max(60, (int) ($response->json('expires_in') ?? 3600) - 60);
        Cache::put($key, $token, $ttl);

        return $token;
    }

    public function forgetToken(): void
    {
        Cache::forget($this->tokenKey());
    }

    protected function tokenKey(): string
    {
        [$account] = $this->credentials();

        return 'torgash.cdek.token.'.($this->test() ? 'test' : 'live').'.'.md5($account);
    }
}
