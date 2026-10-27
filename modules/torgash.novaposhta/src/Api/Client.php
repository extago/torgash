<?php

namespace Torgash\NovaPoshta\Api;

use Illuminate\Support\Facades\Http;

/**
 * A thin wrapper over Nova Poshta's one endpoint.
 *
 * Every call is POST https://api.novaposhta.ua/v2.0/json with
 * {apiKey, modelName, calledMethod, methodProperties}; the reply is
 * {success, data, errors, errorCodes, warnings, info}. Modelled on the
 * carrier's own plug-in client, minus its notification side effects.
 */
class Client
{
    /**
     * The trailing slash is load-bearing. Without it the API answers 301 to
     * the same URL WITH one, the redirect turns the POST into a GET, the body
     * is dropped, and every call comes back "Data is invalid" — with a key,
     * with a wrong key, with no key at all. Measured against the live API.
     */
    public const URL = 'https://api.novaposhta.ua/v2.0/json/';

    /**
     * The pace one key survives indefinitely, in seconds between calls.
     *
     * Measured against the live API, not guessed: firing flat out gets 10 calls
     * through before "To many requests", 0.35 s apart gets 11, 0.7 s apart gets
     * 23 — and 1.5 s apart ran 40 for 40 with no refusal at all. A refusal then
     * clears within 5 seconds, so this is a rolling budget, not a ban.
     *
     * Only bulk work paces itself. A checkout quote must never sleep, so the
     * default client does not throttle: it makes one or two calls and the
     * budget swallows them.
     */
    public const PACE = 1.5;

    protected float $lastCallAt = 0;

    public function __construct(
        protected ?string $key = null,
        protected int $timeout = 30,
        protected ?float $pace = null,
    ) {}

    /** A client for bulk work: at most one call every $seconds. */
    public function paced(float $seconds = self::PACE): static
    {
        $clone = clone $this;
        $clone->pace = $seconds;
        $clone->lastCallAt = 0;

        return $clone;
    }

    /** The key from module settings, unless one was passed in. */
    protected function key(): string
    {
        return $this->key ?: (string) setting('torgash.novaposhta', 'api_key', '');
    }

    public function withKey(string $key): static
    {
        $clone = clone $this;
        $clone->key = $key;

        return $clone;
    }

    /**
     * One call. Returns the `data` array (list of assoc rows); an empty list
     * when the call succeeded but carried no data.
     *
     * @param  array<string, mixed>  $properties
     * @return list<array<string, mixed>>
     *
     * @throws ApiException
     */
    public function call(string $model, string $method, array $properties = []): array
    {
        $this->wait();

        $response = Http::asJson()
            ->timeout($this->timeout)
            ->acceptJson()
            ->post(self::URL, [
                'apiKey' => $this->key(),
                'modelName' => $model,
                'calledMethod' => $method,
                'methodProperties' => (object) $properties,
            ]);

        if ($response->failed()) {
            throw new ApiException("Nova Poshta {$model}/{$method}: HTTP {$response->status()}");
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new ApiException("Nova Poshta {$model}/{$method}: unreadable response");
        }

        if (empty($body['success'])) {
            $errors = array_map('strval', (array) ($body['errors'] ?? []));
            $codes = array_map('strval', (array) ($body['errorCodes'] ?? []));

            throw new ApiException(
                $errors ? implode('; ', $errors) : "Nova Poshta {$model}/{$method}: refused",
                array_values($codes),
            );
        }

        return array_values(array_filter((array) ($body['data'] ?? []), 'is_array'));
    }

    /**
     * Holds the pace, if this client has one. Counted from the previous call's
     * START, so the carrier's own response time is part of the interval rather
     * than added to it.
     */
    protected function wait(): void
    {
        if ($this->pace === null) {
            return;
        }

        $due = $this->lastCallAt + $this->pace;
        $now = microtime(true);

        if ($now < $due) {
            usleep((int) (($due - $now) * 1_000_000));
        }

        $this->lastCallAt = microtime(true);
    }

    /**
     * A call that pages itself: keep asking for the next page until one comes
     * back shorter than $pageSize, yielding rows as they arrive. Nova Poshta
     * paginates on methodProperties.Page (1-based).
     *
     * @param  array<string, mixed>  $properties
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws ApiException
     */
    public function pages(string $model, string $method, array $properties = [], int $pageSize = 500): \Generator
    {
        $page = 1;

        do {
            // Strings, not integers: the API answers "Page is invalid format"
            // to a JSON number, which is how it types every scalar it takes.
            $rows = $this->call($model, $method, $properties + [
                'Page' => (string) $page,
                'Limit' => (string) $pageSize,
            ]);

            yield from $rows;

            $page++;
        } while (count($rows) === $pageSize);
    }
}
