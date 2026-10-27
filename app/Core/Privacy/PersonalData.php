<?php

namespace App\Core\Privacy;

use Carbon\CarbonInterface;

/**
 * Who keeps what personal data, and how it is exported, erased and expired
 * (PLAN §49) — one registry, each module describing ITS OWN rows.
 *
 * A privacy module walks this list; it never names another module's tables,
 * which is the whole reason the registry exists. Owners answer
 * `core.personal_data` (asked lazily, once, with this registry in the context —
 * provider boot order is not ours to depend on, the `media.owners` trick).
 *
 * An entry:
 *   label      — declared English, what the data is ("Order IP addresses")
 *   export     — fn (Subject $s): list<array>   rows to hand the person
 *   erase      — fn (Subject $s): int           rows removed or made anonymous
 *   keep       — declared English: why some of it stays ("kept for accounting")
 *   retention  — fn (CarbonInterface $before): int   clear what is older
 *   default_days — the retention a shop starts with (0 = keep)
 *
 * Every part is optional. Closures resolve whatever they need INSIDE
 * themselves: this registry outlives the request under a persistent worker.
 */
class PersonalData
{
    /** @var array<string, array<string, mixed>> */
    protected array $entries = [];

    protected bool $asked = false;

    protected function ask(): void
    {
        if (! $this->asked) {
            $this->asked = true;
            hook_action('core.personal_data', ['registry' => $this]);
        }
    }

    /** @param  array<string, mixed>  $entry */
    public function register(string $key, array $entry): void
    {
        $this->entries[$key] = $entry + [
            'label' => $key,
            'export' => null,
            'erase' => null,
            'keep' => null,
            'retention' => null,
            'default_days' => 0,
        ];
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $this->ask();

        return $this->entries;
    }

    /** @return array<string, array<string, mixed>> the entries that can be expired */
    public function retained(): array
    {
        return array_filter($this->all(), fn (array $entry) => $entry['retention'] !== null);
    }

    /** @return array<string, list<array<string, mixed>>> label => rows, only what has rows */
    public function export(Subject $subject): array
    {
        $out = [];

        foreach ($this->all() as $entry) {
            if ($entry['export'] && $rows = ($entry['export'])($subject)) {
                $out[label_text($entry['label'], null, '?')] = array_values($rows);
            }
        }

        return $out;
    }

    /**
     * Runs every owner's erase, and says what each did.
     *
     * @return list<array{key: string, label: string, count: int, keep: ?string}>
     */
    public function erase(Subject $subject): array
    {
        $report = [];

        foreach ($this->all() as $key => $entry) {
            if (! $entry['erase']) {
                continue;
            }

            $report[] = [
                'key' => $key,
                'label' => label_text($entry['label'], null, $key),
                'count' => (int) ($entry['erase'])($subject),
                'keep' => $entry['keep'] ? label_text($entry['keep'], null, '') : null,
            ];
        }

        return $report;
    }

    /** Clears one entry's rows older than $before. */
    public function expire(string $key, CarbonInterface $before): int
    {
        $entry = $this->all()[$key] ?? null;

        return $entry && $entry['retention'] ? (int) ($entry['retention'])($before) : 0;
    }

    /** For tests — rebuilt on every boot. */
    public function flush(): void
    {
        $this->entries = [];
        $this->asked = false;
    }
}
