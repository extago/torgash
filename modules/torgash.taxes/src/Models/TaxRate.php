<?php

namespace Torgash\Taxes\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * What one tax charges in one place.
 *
 * The kinds are a shipping zone's, minus the town: a tax rate by settlement is
 * a US local tax, and those are expressed by ZIP code, which is here. A region
 * is stored as `COUNTRY:CODE` because region codes repeat between countries
 * ("12" is a region of both Ukraine and Russia).
 */
class TaxRate extends Model
{
    protected $table = 'm_taxes_rates';

    protected $guarded = [];

    public const KINDS = ['country', 'state', 'postal'];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Tax rate by destination';
    }

    protected function casts(): array
    {
        return [
            'charged' => 'bool',
            'rate_percent' => 'float',
            'rate_amount' => 'int',
            'level' => 'int',
            'position' => 'int',
        ];
    }

    protected static function booted(): void
    {
        // Derived, never typed — the rule shipping's ZoneRule lives by: a row
        // whose narrowness disagreed with what it names would quietly beat a
        // narrower one for ever. An exact postal code is narrower than a
        // pattern of them, which is narrower than a region.
        static::saving(function (TaxRate $rate) {
            $rate->level = self::levelOf($rate->kind, $rate->value);
        });
    }

    public static function levelOf(string $kind, string $value): int
    {
        return match ($kind) {
            'country' => 1,
            'state' => 2,
            'postal' => Str::contains($value, ['*', '?']) ? 3 : 4,
            default => 0,
        };
    }

    public function tax(): BelongsTo
    {
        return $this->belongsTo(Tax::class);
    }
}
