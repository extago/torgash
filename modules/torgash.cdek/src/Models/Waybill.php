<?php

namespace Torgash\Cdek\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An order registered with CDEK. See the migration.
 */
class Waybill extends Model
{
    protected $table = 'm_cdek_waybills';

    protected $guarded = [];

    public const REGISTERING = 'registering';

    public const ACTIVE = 'active';

    public const INVALID = 'invalid';

    public const CANCELLED = 'cancelled';

    /**
     * CDEK statuses after which nothing more happens to a parcel — the status
     * sync stops asking about it. From CDEK's list of order statuses.
     */
    public const FINAL_STATUSES = ['DELIVERED', 'NOT_DELIVERED', 'INVALID', 'REMOVED', 'RETURNED'];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'CDEK waybill';
    }

    protected function casts(): array
    {
        return [
            'tariff_code' => 'int',
            'cod_kopecks' => 'int',
            'statuses' => 'array',
            'errors' => 'array',
            'payload' => 'array',
            'status_at' => 'datetime',
            'checked_at' => 'datetime',
        ];
    }

    /** Still something CDEK is working on — worth asking about. */
    public function isOpen(): bool
    {
        return in_array($this->state, [self::REGISTERING, self::ACTIVE], true)
            && ! in_array($this->status_code, self::FINAL_STATUSES, true);
    }

    /**
     * Can it still be withdrawn? Only before CDEK has the parcel in hand:
     * once it is at a warehouse a deletion is refused, and saying "cancel"
     * there would be a button that lies.
     */
    public function isCancellable(): bool
    {
        return in_array($this->state, [self::REGISTERING, self::ACTIVE], true)
            && in_array($this->status_code, [null, 'ACCEPTED', 'CREATED'], true);
    }

    /** Printable once CDEK has given it a number — a form for an order it has not registered is refused. */
    public function isPrintable(): bool
    {
        return $this->state === self::ACTIVE && (string) $this->cdek_number !== '';
    }

    /** CDEK's public tracking page for this parcel. */
    public function trackingUrl(): ?string
    {
        return $this->cdek_number ? 'https://www.cdek.ru/ru/tracking?order_id='.urlencode($this->cdek_number) : null;
    }
}
