<?php

namespace Torgash\Warehouses;

/**
 * Opening hours of a pickup point, as stored and as read out loud.
 *
 * The stored shape is `{"mon": ["09:00", "18:00"], "sat": null}`: a day that is
 * absent or null is closed. Times are wall-clock strings in the shop's own
 * timezone, not instants — a pickup point opens at nine in its own town, and
 * turning that into a timestamp would only invite a conversion nobody asked for.
 *
 * Rendering groups consecutive days that share a time ("Mon–Fri 09:00–18:00"),
 * because seven lines under every point in a picker is what makes a customer
 * stop reading them.
 */
class Hours
{
    /**
     * In week order — the order the form draws and the summary walks. It has
     * to walk this list rather than the stored array: jsonb does not keep key
     * order, so a schedule saved Mon…Sun comes back mon, sat, tue.
     */
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    /** @return array<string, string> */
    public static function labels(): array
    {
        return [
            'mon' => __('Mon'), 'tue' => __('Tue'), 'wed' => __('Wed'), 'thu' => __('Thu'),
            'fri' => __('Fri'), 'sat' => __('Sat'), 'sun' => __('Sun'),
        ];
    }

    /**
     * Normalises whatever the form posted into the stored shape, dropping the
     * days that are closed. Returns null when nothing is open at all, so "no
     * hours given" stays one value in the database instead of seven nulls.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, array{0: string, 1: string}>|null
     */
    public static function fromInput(array $input): ?array
    {
        $hours = [];

        foreach (self::DAYS as $day) {
            $from = self::time($input[$day]['from'] ?? null);
            $to = self::time($input[$day]['to'] ?? null);

            // Half a range is not a range: an admin who filled one box and
            // saved gets a closed day, not an open-ended one.
            if ($from !== null && $to !== null) {
                $hours[$day] = [$from, $to];
            }
        }

        return $hours === [] ? null : $hours;
    }

    /**
     * The hours as at most a few lines: `[['days' => 'Mon–Fri', 'time' => '09:00–18:00'], …]`.
     *
     * @param  array<string, mixed>|null  $hours
     * @return list<array{days: string, time: string}>
     */
    public static function summary(?array $hours): array
    {
        if (! $hours) {
            return [];
        }

        $labels = self::labels();
        $lines = [];
        $run = null;

        foreach (self::DAYS as $day) {
            $range = self::range($hours[$day] ?? null);

            if ($range === null) {
                $run = null;

                continue;
            }

            // Same times as the day before: extend that line rather than
            // starting another one.
            if ($run !== null && $lines[$run]['time'] === $range) {
                $lines[$run]['last'] = $labels[$day];

                continue;
            }

            $lines[] = ['first' => $labels[$day], 'last' => null, 'time' => $range];
            $run = array_key_last($lines);
        }

        return array_map(fn (array $line) => [
            'days' => $line['last'] === null ? $line['first'] : $line['first'].'–'.$line['last'],
            'time' => $line['time'],
        ], $lines);
    }

    /** @param  mixed  $day */
    protected static function range($day): ?string
    {
        $from = self::time(is_array($day) ? ($day[0] ?? null) : null);
        $to = self::time(is_array($day) ? ($day[1] ?? null) : null);

        return $from !== null && $to !== null ? $from.'–'.$to : null;
    }

    /** Accepts "9:00", "09:00" and "09:00:00"; anything else is not a time. */
    protected static function time($value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', trim($value), $m)) {
            return null;
        }

        if ((int) $m[1] > 23 || (int) $m[2] > 59) {
            return null;
        }

        return sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
    }
}
