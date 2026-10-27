<?php

namespace Torgash\NovaPoshta\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row of Nova Poshta's settlement directory. Keyed by Nova Poshta's UUID,
 * not by an auto id — everything joins on `ref`.
 */
class Settlement extends Model
{
    protected $table = 'm_np_settlements';

    protected $primaryKey = 'ref';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Nova Poshta town';
    }

    protected function casts(): array
    {
        return [
            'has_warehouse' => 'bool',
            'latitude' => 'float',
            'longitude' => 'float',
            'synced_at' => 'datetime',
        ];
    }
}
