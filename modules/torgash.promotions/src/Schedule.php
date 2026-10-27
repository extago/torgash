<?php

namespace Torgash\Promotions;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * When, inside its dates, a promotion is actually open — see the migration
 * for why this is evaluated against the clock instead of materialised into
 * periods the way the usual deal-of-the-day add-on does it.
 *
 * A value object over the stored jsonb, so every answer about a window ("is
 * it open", "when does it close", "when does it open next", "say it in
 * words") comes from one place: the storefront countdown, the pricing gate
 * and the admin list must never disagree about which minute a sale ends.
 *
 * Two readings are fixed here because they are the ones a merchant will trip
 * over, and guessing differently in two places would be worse than either
 * choice:
 *
 *   - `to` at or before `from` means the window CROSSES MIDNIGHT (22:00–02:00
 *     is a night sale), and the days it is listed under are the days it
 *     STARTS on — Friday 22:00–02:00 runs into Saturday morning;
 *   - `to` exactly equal to `from` means the whole day, not a zero-length
 *     window. "00:00 to 00:00" is what a merchant types for "all day", and a
 *     window that can never be open is not something anyone means to save.
 *
 * Times are the shop's own clock (the app timezone). There is deliberately no
 * per-promotion timezone: a shop has opening hours, not a promotion.
 */
class Schedule
{
    /** @param list<array{days: list<int>, from: string, to: string}> $windows */
    protected function __construct(protected array $windows) {}

    /** @param array<string, mixed>|null $raw */
    public static function fromArray(?array $raw): self
    {
        $windows = [];

        foreach ((array) ($raw['windows'] ?? []) as $window) {
            $from = self::minutes($window['from'] ?? null);
            $to = self::minutes($window['to'] ?? null);

            if ($from === null || $to === null) {
                continue;
            }

            $days = array_values(array_unique(array_filter(
                array_map('intval', (array) ($window['days'] ?? [])),
                fn (int $day) => $day >= 1 && $day <= 7,
            )));
            sort($days);

            $windows[] = [
                'days' => $days,
                'from' => self::format($from),
                'to' => self::format($to),
            ];
        }

        return new self($windows);
    }

    public function isEmpty(): bool
    {
        return $this->windows === [];
    }

    /** @return list<array{days: list<int>, from: string, to: string}> */
    public function toArray(): array
    {
        return $this->windows;
    }

    /** An empty schedule is open always — the promotion's dates are then the whole answer. */
    public function isOpenAt(CarbonInterface $at): bool
    {
        return $this->isEmpty() || $this->closesAt($at) !== null;
    }

    /**
     * The end of the window that is open right now — the moment a countdown
     * on the storefront is counting to. NULL when nothing is open (or when
     * there is no schedule at all, which is not a closing time but "no
     * window", and the caller falls back to the promotion's own end date).
     *
     * When two windows overlap, the LATEST end wins: the sale is over when
     * the last thing keeping it open stops.
     */
    public function closesAt(CarbonInterface $at): ?Carbon
    {
        $end = null;

        foreach ($this->occurrencesAround($at) as [$start, $stop]) {
            if ($at->greaterThanOrEqualTo($start) && $at->lessThan($stop) && (! $end || $stop->greaterThan($end))) {
                $end = $stop;
            }
        }

        return $end;
    }

    /**
     * When the next window opens after $at — for "starts at 18:00" on a
     * promotion whose dates have begun but whose clock has not. Looks eight
     * days ahead, which covers any weekly pattern; NULL means the schedule
     * has no windows at all.
     */
    public function opensAt(CarbonInterface $at): ?Carbon
    {
        $next = null;

        for ($day = 0; $day <= 8; $day++) {
            $probe = $at->copy()->addDays($day)->startOfDay();

            foreach ($this->occurrencesOn($probe) as [$start, $stop]) {
                if ($start->greaterThan($at) && (! $next || $start->lessThan($next))) {
                    $next = $start;
                }
            }
        }

        return $next;
    }

    /** The windows in words, for the admin list and the sale page. */
    public function describe(): string
    {
        $names = [
            1 => __('Mon'), 2 => __('Tue'), 3 => __('Wed'), 4 => __('Thu'),
            5 => __('Fri'), 6 => __('Sat'), 7 => __('Sun'),
        ];

        $lines = [];
        foreach ($this->windows as $window) {
            $days = $window['days'] === []
                ? __('every day')
                : implode(', ', array_map(fn (int $day) => $names[$day], $window['days']));

            $lines[] = $window['from'] === $window['to']
                ? $days
                : $days.' '.$window['from'].'–'.$window['to'];
        }

        return implode('; ', $lines);
    }

    /**
     * Concrete [start, stop) instants that could contain $at — today's
     * windows plus yesterday's, because a window that crosses midnight is
     * still open in the small hours of the day AFTER the one it is listed
     * under.
     *
     * @return list<array{0: Carbon, 1: Carbon}>
     */
    protected function occurrencesAround(CarbonInterface $at): array
    {
        return [
            ...$this->occurrencesOn($at->copy()->subDay()->startOfDay()),
            ...$this->occurrencesOn($at->copy()->startOfDay()),
        ];
    }

    /** @return list<array{0: Carbon, 1: Carbon}> */
    protected function occurrencesOn(CarbonInterface $day): array
    {
        $occurrences = [];

        foreach ($this->windows as $window) {
            if ($window['days'] !== [] && ! in_array($day->isoWeekday(), $window['days'], true)) {
                continue;
            }

            $from = self::minutes($window['from']) ?? 0;
            $to = self::minutes($window['to']) ?? 0;
            $length = $to > $from ? $to - $from : $to - $from + 1440;

            $start = $day->copy()->startOfDay()->addMinutes($from);
            $occurrences[] = [$start, $start->copy()->addMinutes($length)];
        }

        return $occurrences;
    }

    /** "HH:MM" (or "HH:MM:SS", which is what a browser's time input may send) to minutes. */
    protected static function minutes(mixed $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})(:\d{2})?$/', trim($time), $m)) {
            return null;
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        return $hours <= 23 && $minutes <= 59 ? $hours * 60 + $minutes : null;
    }

    protected static function format(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }
}
