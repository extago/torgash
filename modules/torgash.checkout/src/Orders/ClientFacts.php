<?php

namespace Torgash\Checkout\Orders;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Torgash\Checkout\Models\Order;

/**
 * What an order writes down about the browser it was placed from — and the
 * shop's choice of which of it (setting `client_facts`).
 *
 * A REGISTRY, answered through `checkout.order.client_facts`, with the same
 * shape as the catalogue's flags and sorts: the core three are here, a module
 * adds its own (a campaign's UTM tags, a fingerprint), and the settings screen
 * offers whatever is registered. Only a ticked fact is captured; one ticked
 * and later unticked stays on the orders that already have it.
 *
 * Captured at placement and nowhere else — PlaceOrder is the one place an
 * order is made, and the placement request is the one moment the browser is
 * there to be asked. An order placed on a customer's behalf captures nothing:
 * that request is the manager's.
 *
 * A declaration:
 *
 *     'user_agent' => [
 *         'label' => 'Browser and device',            // English, translated by lang files
 *         'capture' => fn (Request $r) => ?string,     // null = nothing to write
 *         'column' => null,                            // an order column of its own, else the `client` bag
 *         'format' => fn (string $value) => string,    // how the order card prints it (optional)
 *     ]
 *
 * The order's own `locale` is not one of these: it is what the letters and a
 * reprint are written in, not something the shop may decide not to know.
 */
class ClientFacts
{
    /** Ticked on a shop that has said nothing. */
    public const DEFAULTS = ['ip_address', 'user_agent', 'languages'];

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $core = [
            'ip_address' => [
                'label' => 'IP address',
                'capture' => fn (Request $request) => $request->ip(),
                // A column: it is searched in the order list and counted on the card.
                'column' => 'ip_address',
            ],
            'user_agent' => [
                'label' => 'Browser and device',
                'capture' => fn (Request $request) => $this->clip($request->userAgent(), 500),
            ],
            'languages' => [
                'label' => 'Languages of the browser',
                'capture' => fn (Request $request) => $this->clip($request->header('Accept-Language'), 200),
                // "ru-RU,ru;q=0.9,en;q=0.8" reads as "ru-RU, ru, en".
                'format' => fn (string $value) => collect(explode(',', $value))
                    ->map(fn ($part) => trim(Str::before($part, ';')))
                    ->filter()
                    ->implode(', '),
            ],
        ];

        $all = hook_filter('checkout.order.client_facts', $core);

        return array_map(fn (array $fact) => $fact + [
            'label' => 'Unnamed',
            'capture' => fn () => null,
            'column' => null,
            'format' => null,
        ], $all);
    }

    /** @return list<string> the registered keys the shop has ticked */
    public function enabled(): array
    {
        $ticked = (array) setting('torgash.checkout', 'client_facts', self::DEFAULTS);

        return array_values(array_intersect(array_keys($this->all()), $ticked));
    }

    /**
     * What to write onto a new order: its own columns plus the `client` bag.
     *
     * @return array<string, mixed>
     */
    public function capture(?Request $request): array
    {
        $columns = ['client' => null];

        if (! $request) {
            return $columns;
        }

        $all = $this->all();
        $bag = [];

        foreach ($this->enabled() as $key) {
            $fact = $all[$key];
            $value = ($fact['capture'])($request);

            if ($value === null || $value === '') {
                continue;
            }

            if ($fact['column']) {
                $columns[$fact['column']] = $value;
            } else {
                $bag[$key] = $value;
            }
        }

        $columns['client'] = $bag ?: null;

        return $columns;
    }

    /**
     * The bag's facts as the order card prints them, in registry order. The
     * address is left out: the card draws it with its own links.
     *
     * @return list<array{key: string, label: string, value: string, raw: string}>
     */
    public function describe(Order $order): array
    {
        $bag = (array) ($order->client ?? []);
        $rows = [];

        foreach ($this->all() as $key => $fact) {
            if ($fact['column'] || ! isset($bag[$key]) || ! is_scalar($bag[$key])) {
                continue;
            }

            $raw = (string) $bag[$key];

            $rows[] = [
                'key' => $key,
                'label' => label_text($fact['label']),
                'value' => $fact['format'] ? (string) ($fact['format'])($raw) : $raw,
                'raw' => $raw,
            ];
        }

        // A fact whose module has gone still belongs to the order: shown by
        // its key rather than hidden.
        foreach (array_diff_key($bag, $this->all()) as $key => $value) {
            if (is_scalar($value)) {
                $rows[] = ['key' => (string) $key, 'label' => (string) $key, 'value' => (string) $value, 'raw' => (string) $value];
            }
        }

        return $rows;
    }

    protected function clip(?string $value, int $length): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
