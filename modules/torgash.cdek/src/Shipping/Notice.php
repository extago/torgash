<?php

namespace Torgash\Cdek\Shipping;

/**
 * "CDEK was asked about this destination and had no point to offer" —
 * remembered for the length of one quote, so the page can say so, and WHY.
 *
 * The same three causes as Nova Poshta's, with the same three answers: a town
 * with nothing is told where the nearest points are; a parcel too heavy (or
 * too big for every locker) is told that, because another town would not
 * help; and a town whose points we could not fetch is told to try again.
 *
 * A per-request singleton, reset with the request like every other memo here.
 */
class Notice
{
    public const NONE = 'none';

    public const TOO_HEAVY = 'too_heavy';

    public const UNKNOWN = 'unknown';

    /** The more we know, the less useful — see the Nova Poshta original. */
    protected const RANK = [self::NONE => 0, self::TOO_HEAVY => 1, self::UNKNOWN => 2];

    protected ?int $placeId = null;

    protected ?string $reason = null;

    /** @var array<string, mixed> */
    protected array $params = [];

    /** @param  array<string, mixed>  $params  what the sentence needs (`kg`) */
    public function noPoints(?int $placeId, string $reason = self::NONE, array $params = []): void
    {
        if (! $placeId || ! isset(self::RANK[$reason])) {
            return;
        }

        if ($this->reason !== null && self::RANK[$reason] <= self::RANK[$this->reason]) {
            return;
        }

        $this->placeId = $placeId;
        $this->reason = $reason;
        $this->params = $params;
    }

    public function place(): ?int
    {
        return $this->placeId;
    }

    public function reason(): string
    {
        return $this->reason ?? self::NONE;
    }

    /** @return array<string, mixed> */
    public function params(): array
    {
        return $this->params;
    }

    public function forget(): void
    {
        $this->placeId = null;
        $this->reason = null;
        $this->params = [];
    }
}
