<?php

namespace App\Core\Backups;

use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * When a plan runs (PLAN §67.2): a LIST of moments, each one of
 *
 *     {"kind": "hourly",  "every": 6, "minute": 15}
 *     {"kind": "daily",   "at": "12:00"}
 *     {"kind": "weekly",  "days": [1, 4], "at": "03:40"}    0 = Sunday
 *     {"kind": "monthly", "day": 1, "at": "02:00"}
 *     {"kind": "cron",    "expression": "30 2 * * 1-5"}
 *
 * — so "the database at noon and at midnight" is two entries. Each becomes a
 * cron expression read in the shop's clock (APP_TIMEZONE).
 */
class PlanSchedule
{
    public const KINDS = ['hourly', 'daily', 'weekly', 'monthly', 'cron'];

    /** @param  array<string, mixed>  $entry */
    public static function cron(array $entry): ?string
    {
        [$hour, $minute] = self::time((string) ($entry['at'] ?? ''));

        return match ($entry['kind'] ?? null) {
            'hourly' => sprintf('%d %s * * *', min(59, max(0, (int) ($entry['minute'] ?? 0))), self::every((int) ($entry['every'] ?? 1))),
            'daily' => $hour === null ? null : "$minute $hour * * *",
            'weekly' => $hour === null || ! self::days($entry) ? null : "$minute $hour * * ".implode(',', self::days($entry)),
            'monthly' => $hour === null ? null : sprintf('%d %d %d * *', $minute, $hour, min(28, max(1, (int) ($entry['day'] ?? 1)))),
            'cron' => self::validCron((string) ($entry['expression'] ?? '')) ? trim((string) $entry['expression']) : null,
            default => null,
        };
    }

    /**
     * The entries as typed, cleaned: what cannot become a cron expression is
     * reported, not dropped in silence.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return array{0: list<array<string, mixed>>, 1: list<string>} [entries, errors]
     */
    public static function clean(array $entries): array
    {
        $kept = [];
        $errors = [];

        foreach (array_values($entries) as $i => $entry) {
            $kind = (string) ($entry['kind'] ?? '');

            if ($kind === '' || ! in_array($kind, self::KINDS, true)) {
                continue;
            }

            $clean = match ($kind) {
                'hourly' => ['kind' => 'hourly', 'every' => min(23, max(1, (int) ($entry['every'] ?? 1))), 'minute' => min(59, max(0, (int) ($entry['minute'] ?? 0)))],
                'daily' => ['kind' => 'daily', 'at' => trim((string) ($entry['at'] ?? ''))],
                'weekly' => ['kind' => 'weekly', 'days' => self::days($entry), 'at' => trim((string) ($entry['at'] ?? ''))],
                'monthly' => ['kind' => 'monthly', 'day' => min(28, max(1, (int) ($entry['day'] ?? 1))), 'at' => trim((string) ($entry['at'] ?? ''))],
                'cron' => ['kind' => 'cron', 'expression' => trim((string) ($entry['expression'] ?? ''))],
            };

            if (self::cron($clean) === null) {
                $errors[] = match ($kind) {
                    'weekly' => __('Moment :n: choose at least one day and a time.', ['n' => $i + 1]),
                    'cron' => __('Moment :n: ":expression" is not a cron expression.', ['n' => $i + 1, 'expression' => $clean['expression']]),
                    default => __('Moment :n: the time must be HH:MM.', ['n' => $i + 1]),
                };

                continue;
            }

            $kept[] = $clean;
        }

        return [$kept, $errors];
    }

    /** One entry in words, in the current language. */
    public static function describe(array $entry): string
    {
        $at = (string) ($entry['at'] ?? '');
        $minute = sprintf('%02d', (int) ($entry['minute'] ?? 0));

        return match ($entry['kind'] ?? null) {
            'hourly' => (int) ($entry['every'] ?? 1) === 1
                ? __('Every hour at :minute min', ['minute' => $minute])
                : __('Every :n hours at :minute min', ['n' => (int) $entry['every'], 'minute' => $minute]),
            'daily' => __('Every day at :time', ['time' => $at]),
            'weekly' => __(':days at :time', ['days' => implode(', ', array_map(fn (int $d) => self::dayName($d), self::days($entry))), 'time' => $at]),
            'monthly' => __('Day :day of every month at :time', ['day' => (int) ($entry['day'] ?? 1), 'time' => $at]),
            'cron' => 'cron: '.($entry['expression'] ?? ''),
            default => '',
        };
    }

    /**
     * The next moments over all entries, soonest first.
     *
     * @param  list<array<string, mixed>>  $entries
     * @return list<Carbon>
     */
    public static function next(array $entries, int $count = 5, ?Carbon $from = null): array
    {
        $from ??= now();
        $timezone = (string) config('app.timezone');
        $moments = [];

        foreach ($entries as $entry) {
            $expression = self::cron($entry);

            if ($expression === null) {
                continue;
            }

            foreach ((new CronExpression($expression))->getMultipleRunDates($count, $from->copy()->setTimezone($timezone), false, false, $timezone) as $date) {
                $moment = Carbon::instance($date)->setTimezone($timezone);
                $moments[$moment->format('Y-m-d H:i')] = $moment;
            }
        }

        ksort($moments);

        return array_values(array_slice($moments, 0, $count));
    }

    /** A short weekday name in the current language; 0 = Sunday. */
    public static function dayName(int $day): string
    {
        // 1 November 2026 is a Sunday.
        return Carbon::create(2026, 11, 1 + ($day % 7))->locale(app()->getLocale())->isoFormat('dd');
    }

    public static function validCron(string $expression): bool
    {
        try {
            return trim($expression) !== '' && CronExpression::isValidExpression(trim($expression));
        } catch (Throwable) {
            return false;
        }
    }

    /** @return array{0: ?int, 1: ?int} */
    protected static function time(string $at): array
    {
        if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', trim($at), $m)) {
            return [null, null];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /** @return list<int> */
    protected static function days(array $entry): array
    {
        $days = array_map('intval', (array) ($entry['days'] ?? []));
        $days = array_values(array_unique(array_filter($days, fn (int $d) => $d >= 0 && $d <= 6)));
        sort($days);

        return $days;
    }

    protected static function every(int $hours): string
    {
        $hours = min(23, max(1, $hours));

        return $hours === 1 ? '*' : '*/'.$hours;
    }
}
