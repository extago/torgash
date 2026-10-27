<?php

namespace Torgash\Shipping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ZoneRule extends Model
{
    protected $table = 'm_shipping_zone_rules';

    protected $guarded = [];

    /**
     * How narrow each kind of rule is. A town beats a region beats a country,
     * which is the whole ordering the matcher needs — and the reason a shop can
     * say "everywhere by courier, but in our own city also pickup".
     */
    public const LEVELS = [
        'country' => 1,
        'state' => 2,
        'place' => 3,
        'postal_prefix' => 3,
        'postal_glob' => 3,
        'postal' => 4,
    ];

    public const KINDS = [
        'country' => 'Country',
        'state' => 'Region',
        'place' => 'Town',
        'postal_prefix' => 'Postal code starts with',
        'postal_glob' => 'Postal code pattern',
        'postal' => 'Postal code',
    ];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Delivery zone rule';
    }

    protected static function booted(): void
    {
        // The level is derived, never typed: a rule whose narrowness disagreed
        // with its kind would make one zone quietly beat another forever.
        static::saving(function (ZoneRule $rule) {
            $rule->level = $rule->value === '*' ? 0 : (self::LEVELS[$rule->kind] ?? 1);
        });
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }
}
