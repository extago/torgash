<?php

namespace Torgash\NovaPoshta\Shipping;

/**
 * "Nova Poshta was asked about this destination and had nothing to offer" —
 * remembered for the length of one quote, so the page can say so, and WHY.
 *
 * Why a note rather than an offer: a method the customer cannot complete is a
 * dead end at the last step of checkout, and an order with no branch chosen is
 * refused at placement anyway. So the carrier stays out of the list and
 * explains itself underneath it instead — which is the one thing a shop with a
 * village in the address field has never been able to do.
 *
 * Set by the provider, which is the only thing that knows it was asked and
 * came back empty: guessing from outside ("there is no Nova Poshta option, so
 * presumably…") would also fire for a shop that sells no Nova Poshta at all.
 *
 * **An empty list has three causes and they deserve three different answers.**
 * A village with nothing is told where the nearest points are; a parcel too
 * heavy for any point in a town full of them is told that, because the way out
 * is a different basket or a courier, not a different town; and a town whose
 * branches we could not fetch is told to try again, because sending that
 * customer to the next town would be advice built on something we do not know.
 * Collapsing the three into "no branches here" is how the shop states, with
 * confidence, something that is false twice out of three times.
 *
 * A per-request singleton, reset with the request like every other memo here.
 */
class Notice
{
    /** The carrier has no pickup point in this town at all. */
    public const NONE = 'none';

    /** It has them, and not one takes a parcel this heavy. */
    public const TOO_HEAVY = 'too_heavy';

    /** What it has is unknown: the last attempt to ask was refused. */
    public const UNKNOWN = 'unknown';

    /**
     * Two methods can quote the same destination — "to a branch" and "to a
     * locker" — and both can come back empty for different reasons. The more
     * we know, the less useful: "we could not ask" outranks the rest because
     * it is the only one that must not be answered with directions, and "too
     * heavy" outranks "nothing here" because it is the one the customer can
     * act on.
     */
    protected const RANK = [self::NONE => 0, self::TOO_HEAVY => 1, self::UNKNOWN => 2];

    protected ?int $placeId = null;

    protected ?string $reason = null;

    /** @var array<string, mixed> */
    protected array $params = [];

    /**
     * The carrier has no pickup point to offer in this place.
     *
     * @param  array<string, mixed>  $params  what the sentence needs (`kg`)
     */
    public function noBranches(?int $placeId, string $reason = self::NONE, array $params = []): void
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
