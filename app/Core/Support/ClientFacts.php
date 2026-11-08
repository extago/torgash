<?php

namespace App\Core\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * What a row writes down about the browser it came from — and the shop's
 * choice of which of it. One subclass per kind of row (an order, an enquiry):
 * the subclass says where the shop's ticks live, this class knows the facts.
 *
 * A REGISTRY, answered through `core.client_facts` for every kind of row (a
 * campaign's UTM tags, a fingerprint); the settings screens offer whatever is
 * registered. Only a ticked fact is captured; one ticked and later unticked
 * stays on the rows that already have it.
 *
 * Captured when the row is made and nowhere else — that request is the one
 * moment the browser is there to be asked. A row made on somebody's behalf
 * captures nothing (pass null): that request is the manager's.
 *
 * A declaration:
 *
 *     'user_agent' => [
 *         'label' => 'Browser and device',            // English, translated by lang files
 *         'capture' => fn (Request $r) => ?string,     // null = nothing to write
 *         'format' => fn (string $value) => string,    // how a card prints it (optional)
 *     ]
 *
 * Whatever keeps these has a jsonb `client` column for the bag and an
 * `ip_address` column of its own: the address is searched and counted.
 * `column` is honoured only there — a fact declared for every kind of row
 * cannot name a column that only one of their tables has.
 */
abstract class ClientFacts
{
    /** Ticked on a shop that has said nothing. */
    public const DEFAULTS = ['ip_address', 'user_agent', 'languages'];

    /** @return list<string> what this shop has ticked for this kind of row */
    abstract protected function ticked(): array;

    /**
     * Facts only this kind of row has.
     *
     * @param  array<string, array<string, mixed>>  $facts
     * @return array<string, array<string, mixed>>
     */
    protected function extended(array $facts): array
    {
        return $facts;
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $facts = hook_filter('core.client_facts', [
            'ip_address' => [
                'label' => 'IP address',
                'capture' => fn (Request $request) => $request->ip(),
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
        ]);

        foreach ($facts as $key => $fact) {
            if ($key !== 'ip_address') {
                unset($facts[$key]['column']);
            }
        }

        return array_map(fn (array $fact) => $fact + [
            'label' => 'Unnamed',
            'capture' => fn () => null,
            'column' => null,
            'format' => null,
        ], $this->extended($facts));
    }

    /** @return list<string> the registered keys the shop has ticked */
    public function enabled(): array
    {
        return array_values(array_intersect(array_keys($this->all()), $this->ticked()));
    }

    /** @return array<string, string> key => label, for the settings screen */
    public function options(): array
    {
        return array_map(fn (array $fact) => $fact['label'], $this->all());
    }

    /**
     * What to write onto a new row: its own columns plus the `client` bag.
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
     * The bag's facts as a card prints them, in registry order. The address is
     * left out: a card draws it with its own links.
     *
     * @param  array<string, mixed>|null  $bag
     * @return list<array{key: string, label: string, value: string, raw: string}>
     */
    public function describeBag(?array $bag): array
    {
        $bag ??= [];
        $all = $this->all();
        $rows = [];

        foreach ($all as $key => $fact) {
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

        // A fact whose module has gone still belongs to the row: shown by its
        // key rather than hidden.
        foreach (array_diff_key($bag, $all) as $key => $value) {
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
