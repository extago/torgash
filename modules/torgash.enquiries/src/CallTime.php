<?php

namespace Torgash\Enquiries;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * "When to call" (a field of type `call_time`, the callback's): a part of
 * the day or the person's own hours, BY THEIR OWN CLOCK — and the clock
 * itself, taken from the browser (`Intl…timeZone`) without asking, because
 * a shop in a country of eleven time zones must not ring Vladivostok at
 * three in the morning because "evening" was read by Moscow's clock.
 *
 * Stored as the field's value: `{when, from?, to?, zone?}`. The customer
 * reads it back as they gave it (`describe()`); staff are also told what
 * it is by the SHOP's clock and what time it is there now (`staffNote()`).
 * A row written before this — a bare "evening" — still reads (`from()`
 * answers null and the plain option label is printed).
 */
final class CallTime
{
    /** The parts of the day, as hours by the person's clock. */
    public const PARTS = [
        'morning' => ['09:00', '12:00'],
        'afternoon' => ['12:00', '17:00'],
        'evening' => ['17:00', '21:00'],
    ];

    /** The field's options, `custom` last: its own hours. */
    public const OPTIONS = [
        'any' => 'Any time',
        'morning' => 'Morning',
        'afternoon' => 'Afternoon',
        'evening' => 'Evening',
        'custom' => 'At hours of my choosing',
    ];

    private function __construct(
        public readonly string $when,
        public readonly ?string $from,
        public readonly ?string $to,
        public readonly ?string $zone,
    ) {}

    /** The stored value, or null for one that is not this shape (an old plain string). */
    public static function from(mixed $value): ?self
    {
        if (! is_array($value) || ! isset($value['when']) || ! is_string($value['when'])) {
            return null;
        }

        $zone = is_string($value['zone'] ?? null) && in_array($value['zone'], DateTimeZone::listIdentifiers(), true) ? $value['zone'] : null;
        $time = fn ($v) => is_string($v) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : null;

        return new self($value['when'], $time($value['from'] ?? null), $time($value['to'] ?? null), $zone);
    }

    /**
     * What a request carried, cleaned to what is kept: a known part of the
     * day, own hours only with `custom`, a real zone or none.
     *
     * @return array{when: string, from?: string, to?: string, zone?: string}|null
     */
    public static function clean(mixed $typed): ?array
    {
        $call = self::from($typed);

        if (! $call || ! array_key_exists($call->when, self::OPTIONS)) {
            return null;
        }

        return array_filter([
            'when' => $call->when,
            'from' => $call->when === 'custom' ? $call->from : null,
            'to' => $call->when === 'custom' ? $call->to : null,
            'zone' => $call->zone,
        ], fn ($v) => $v !== null);
    }

    /** As the person said it: "Morning", "From 10:00 to 12:00". */
    public function describe(array $optionLabels = []): string
    {
        if ($this->when === 'custom') {
            return match (true) {
                $this->from && $this->to => __('From :from to :to', ['from' => $this->from, 'to' => $this->to]),
                (bool) $this->from => __('From :from', ['from' => $this->from]),
                (bool) $this->to => __('Until :to', ['to' => $this->to]),
                default => (string) ($optionLabels['custom'] ?? __(self::OPTIONS['custom'])),
            };
        }

        return (string) ($optionLabels[$this->when] ?? __(self::OPTIONS[$this->when] ?? $this->when));
    }

    /**
     * What staff need beside it, when the person's clock is not the shop's:
     * the hours by the shop's clock, and the time there now —
     * "03:00–05:00 by your clock · it is 03:15 there now (+7 h)". Null when
     * the clocks agree or the zone is unknown.
     */
    public function staffNote(?CarbonImmutable $now = null): ?string
    {
        if ($this->zone === null) {
            return null;
        }

        $shopZone = (string) config('app.timezone');
        $now ??= CarbonImmutable::now($shopZone);
        $theirs = $now->setTimezone($this->zone);
        $difference = ($theirs->getOffset() - $now->setTimezone($shopZone)->getOffset()) / 3600;

        if ($difference == 0) {
            return null;
        }

        $parts = [];
        [$from, $to] = $this->when === 'custom' ? [$this->from, $this->to] : (self::PARTS[$this->when] ?? [null, null]);

        if ($from || $to) {
            $shift = fn (?string $hm) => $hm === null ? '…' : CarbonImmutable::parse($theirs->toDateString().' '.$hm, $this->zone)->setTimezone($shopZone)->format('H:i');
            $parts[] = __(':from–:to by your clock', ['from' => $shift($from), 'to' => $shift($to)]);
        }

        $sign = $difference > 0 ? '+' : '−';
        $hours = rtrim(rtrim(number_format(abs($difference), 1, '.', ''), '0'), '.');
        $parts[] = __('it is :time there now (:difference h)', ['time' => $theirs->format('H:i'), 'difference' => $sign.$hours]);

        return implode(' · ', $parts);
    }
}
