<?php

namespace Torgash\Guard\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Torgash\Guard\Blocking\Signals;

/**
 * One address, and what the shop has decided about it.
 *
 * @property string $ip
 * @property string $mode
 * @property string $reason
 * @property array|null $signals
 * @property int $strikes
 * @property int $hits
 */
class Block extends Model
{
    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Address block';
    }

    /** Written down and applied. */
    public const ENFORCED = 'enforced';

    /** Written down and NOT applied — the default, and the whole of watch mode. */
    public const OBSERVED = 'observed';

    protected $table = 'm_guard_blocks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'signals' => 'array',
            'until' => 'datetime',
            'released_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /** Still standing: neither lifted by hand nor run out on its own. */
    public function isActive(): bool
    {
        return $this->released_at === null && ($this->until === null || $this->until->isFuture());
    }

    public function isEnforced(): bool
    {
        return $this->mode === self::ENFORCED && $this->isActive();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('released_at')
            ->where(fn (Builder $q) => $q->whereNull('until')->orWhere('until', '>', now()));
    }

    /** What it was doing, in words, longest first: "6 wrong passwords, 4 refused forms". */
    public function what(): string
    {
        $counts = $this->signals ?: [];
        arsort($counts);

        $parts = [];

        foreach ($counts as $signal => $count) {
            $parts[] = Signals::label((string) $signal).': '.$count;
        }

        return $parts ? implode(', ', $parts) : Signals::label($this->reason);
    }

    /** How it reads on the list, without a sentence being stored anywhere. */
    public function state(): string
    {
        if ($this->released_at !== null) {
            return __('Released');
        }

        if ($this->until !== null && $this->until->isPast()) {
            return __('Ran out');
        }

        return $this->mode === self::ENFORCED ? __('Blocked') : __('Would have been blocked');
    }

    public function badge(): string
    {
        if (! $this->isActive()) {
            return 'badge-ghost';
        }

        return $this->mode === self::ENFORCED ? 'badge-error' : 'badge-warning';
    }
}
