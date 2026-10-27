<?php

namespace Torgash\Points\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One movement of a customer's points, signed. Written only by Ledger, never
 * updated — a correction is another line.
 */
class Entry extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'm_points_ledger';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'points' => 'int',
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** English source words, translated when read. */
    public function typeLabel(): string
    {
        return __(match ($this->type) {
            'earn' => 'Earned for an order',
            'spend' => 'Spent on an order',
            'refund' => 'Returned from an order',
            'revoke' => 'Taken back from an order',
            'adjust' => 'Corrected by hand',
            'expire' => 'Expiry',
            default => (string) $this->type,
        });
    }
}
