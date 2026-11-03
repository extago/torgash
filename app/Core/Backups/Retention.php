<?php

namespace App\Core\Backups;

use Illuminate\Support\Carbon;

/**
 * Which copies in one destination go (PLAN §67.4). Rules, any of them:
 *
 * - `last`: the newest N;
 * - `daily` / `weekly` / `monthly` / `yearly`: the newest copy of each of the
 *   last N days / weeks / months / years that have one (grandfather–father–
 *   son: a year in ~25 copies instead of 365);
 * - `days`: none older than N days;
 * - `gigabytes`: no more than N GB together, the oldest going first.
 *
 * The counting rules add up (a copy one of them keeps is kept); `days` and
 * `gigabytes` then take away. No rule at all keeps everything. Never deleted
 * whatever the rules say: the newest copy and a pinned one.
 */
class Retention
{
    public const RULES = ['last', 'daily', 'weekly', 'monthly', 'yearly', 'days', 'gigabytes'];

    /**
     * @param  list<array{id: int, at: Carbon, bytes: ?int, pinned: bool}>  $copies
     * @param  array<string, int|float|string|null>  $rules
     * @return list<int> the ids to delete
     */
    public static function expired(array $copies, array $rules, ?Carbon $now = null): array
    {
        $now ??= now();
        $rules = array_map(fn ($v) => max(0, (float) $v), array_intersect_key($rules, array_flip(self::RULES)));

        if (! array_filter($rules) || ! $copies) {
            return [];
        }

        usort($copies, fn ($a, $b) => $b['at'] <=> $a['at'] ?: $b['id'] <=> $a['id']);

        $counting = array_filter(array_intersect_key($rules, array_flip(['last', 'daily', 'weekly', 'monthly', 'yearly'])));
        $keep = [];

        if (! $counting) {
            foreach ($copies as $copy) {
                $keep[$copy['id']] = true;
            }
        }

        foreach (array_slice($copies, 0, (int) ($rules['last'] ?? 0)) as $copy) {
            $keep[$copy['id']] = true;
        }

        foreach (['daily' => 'Y-m-d', 'weekly' => 'o-W', 'monthly' => 'Y-m', 'yearly' => 'Y'] as $rule => $bucket) {
            $wanted = (int) ($rules[$rule] ?? 0);
            $seen = [];

            foreach ($copies as $copy) {
                $key = $copy['at']->format($bucket);

                if (isset($seen[$key])) {
                    continue;
                }

                if (count($seen) >= $wanted) {
                    break;
                }

                $seen[$key] = true;
                $keep[$copy['id']] = true;
            }
        }

        if ($days = (float) ($rules['days'] ?? 0)) {
            $limit = $now->copy()->subSeconds((int) round($days * 86400));

            foreach ($copies as $copy) {
                if ($copy['at']->lt($limit)) {
                    unset($keep[$copy['id']]);
                }
            }
        }

        if ($gigabytes = (float) ($rules['gigabytes'] ?? 0)) {
            $budget = $gigabytes * 1024 ** 3;
            $total = 0;

            foreach ($copies as $copy) {
                if (! isset($keep[$copy['id']])) {
                    continue;
                }

                $total += (int) ($copy['bytes'] ?? 0);

                if ($total > $budget) {
                    unset($keep[$copy['id']]);
                }
            }
        }

        $keep[$copies[0]['id']] = true;

        return array_values(array_map(
            fn (array $copy) => $copy['id'],
            array_filter($copies, fn (array $copy) => ! isset($keep[$copy['id']]) && ! $copy['pinned']),
        ));
    }

    /** The rules in words, for a list. */
    public static function describe(array $rules): string
    {
        $parts = [];

        foreach (self::RULES as $rule) {
            $n = (float) ($rules[$rule] ?? 0);

            if ($n <= 0) {
                continue;
            }

            $parts[] = match ($rule) {
                'last' => __('the newest :n', ['n' => (int) $n]),
                'daily' => __(':n daily', ['n' => (int) $n]),
                'weekly' => __(':n weekly', ['n' => (int) $n]),
                'monthly' => __(':n monthly', ['n' => (int) $n]),
                'yearly' => __(':n yearly', ['n' => (int) $n]),
                'days' => __('not older than :n days', ['n' => (int) $n]),
                'gigabytes' => __('at most :n GB', ['n' => rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.')]),
            };
        }

        return $parts ? implode(', ', $parts) : __('every copy');
    }
}
