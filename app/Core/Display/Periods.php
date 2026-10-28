<?php

namespace App\Core\Display;

use Carbon\CarbonInterface;

/**
 * From–to pairs in a display rule (PLAN §51): days of the year for the
 * holidays, hours of the day for an evening offer — read on the shop's own
 * clock (`shop_now()`).
 *
 * A date is `25.12` — every year — or `25.12.2026` — that year only: a
 * holiday whose date moves (Easter, Black Friday) is given its date for the
 * year. A yearly period may run over New Year (25.12–08.01), an evening may
 * run over midnight (22:00–02:00). Both ends are included; a period with no
 * end is that one day.
 */
class Periods
{
    public const FORMATS = ['date', 'time'];

    /**
     * Posted rows made into periods the engine can read; anything else is
     * dropped — a half-typed row must not hide the thing it was added to.
     *
     * @return list<array{from: string, to: string}>
     */
    public static function normalise(mixed $posted, string $format): array
    {
        $periods = [];

        foreach ((array) $posted as $row) {
            if (! is_array($row)) {
                continue;
            }

            $from = self::clean((string) ($row['from'] ?? ''), $format);
            $typedTo = trim((string) ($row['to'] ?? ''));
            // No end is that one day; an end that is not a date is a typo, not "one day".
            $to = $typedTo === '' ? $from : self::clean($typedTo, $format);

            // A date period is yearly at both ends or dated at both ends.
            if ($from === null || $to === null || ($format === 'date' && (strlen($from) === 5) !== (strlen($to) === 5))) {
                continue;
            }

            $periods[] = ['from' => $from, 'to' => $to];
        }

        return $periods;
    }

    /** @param list<array{from: string, to: string}> $periods */
    public static function contains(array $periods, string $format, ?CarbonInterface $now = null): bool
    {
        $now ??= shop_now();

        foreach ($periods as $period) {
            if (is_array($period) && self::within($period, $format, $now)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The next moment `contains()` may answer differently: a period's start,
     * or the minute after its end (both ends are included) — on the shop's
     * clock, for a page kept ready only until then (PLAN §61). A date answers
     * differently at midnight at the earliest.
     *
     * @param  list<array{from: string, to?: string}>  $periods
     */
    public static function nextChange(array $periods, string $format, ?CarbonInterface $now = null): CarbonInterface
    {
        $now ??= shop_now();
        $midnight = $now->copy()->addDay()->startOfDay();

        if ($format !== 'time') {
            return $midnight;
        }

        $next = $midnight;

        foreach ($periods as $period) {
            if (! is_array($period) || empty($period['from'])) {
                continue;
            }

            foreach ([(string) $period['from'], (string) ($period['to'] ?? $period['from'])] as $i => $edge) {
                [$hour, $minute] = array_map('intval', explode(':', $edge) + [1 => 0]);
                $at = $now->copy()->setTime($hour, $minute)->addMinutes($i);

                if ($at->lte($now)) {
                    $at = $at->addDay();
                }

                if ($at->lt($next)) {
                    $next = $at;
                }
            }
        }

        return $next;
    }

    /** "25.12–08.01, 08.03" — a rule in the admin's words. */
    public static function describe(mixed $periods): string
    {
        return collect((array) $periods)
            ->filter(fn ($period) => is_array($period) && ! empty($period['from']))
            ->map(fn (array $period) => $period['from'].(($period['to'] ?? $period['from']) !== $period['from'] ? '–'.$period['to'] : ''))
            ->implode(', ');
    }

    /** @param array{from: string, to?: string} $period */
    protected static function within(array $period, string $format, CarbonInterface $now): bool
    {
        $from = (string) $period['from'];
        $to = (string) ($period['to'] ?? $from);

        if ($format === 'time') {
            $at = $now->format('H:i');

            return $from <= $to ? $at >= $from && $at <= $to : $at >= $from || $at <= $to;
        }

        if (strlen($from) === 10) {
            $day = $now->format('Y-m-d');

            return $day >= self::iso($from) && $day <= self::iso($to);
        }

        // Yearly: compared as month and day, so 25.12–08.01 runs over New Year.
        $day = $now->format('m-d');
        [$start, $end] = [self::monthDay($from), self::monthDay($to)];

        return $start <= $end ? $day >= $start && $day <= $end : $day >= $start || $day <= $end;
    }

    /** "5.3" → "05.03"; "31.02" → null; "8:00" → "08:00". */
    protected static function clean(string $value, string $format): ?string
    {
        $value = trim($value);

        if ($format === 'time') {
            if (! preg_match('/^(\d{1,2})[:.](\d{2})$/', $value, $m) || (int) $m[1] > 23 || (int) $m[2] > 59) {
                return null;
            }

            return sprintf('%02d:%02d', $m[1], $m[2]);
        }

        if (! preg_match('/^(\d{1,2})\.(\d{1,2})(?:\.(\d{4}))?$/', $value, $m)) {
            return null;
        }

        // 29 February is a real yearly date: checked against a leap year.
        if (! checkdate((int) $m[2], (int) $m[1], (int) ($m[3] ?? 2024))) {
            return null;
        }

        return sprintf('%02d.%02d', $m[1], $m[2]).(isset($m[3]) ? '.'.$m[3] : '');
    }

    protected static function monthDay(string $date): string
    {
        return substr($date, 3, 2).'-'.substr($date, 0, 2);
    }

    protected static function iso(string $date): string
    {
        return substr($date, 6, 4).'-'.substr($date, 3, 2).'-'.substr($date, 0, 2);
    }
}
