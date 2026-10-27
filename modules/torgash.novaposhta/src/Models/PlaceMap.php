<?php

namespace Torgash\NovaPoshta\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The bridge: one of our place ids to one Nova Poshta settlement. Its presence
 * is what makes Nova Poshta an option for that place at all.
 */
class PlaceMap extends Model
{
    protected $table = 'm_np_place_map';

    protected $primaryKey = 'place_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Nova Poshta town match';
    }

    protected function casts(): array
    {
        return [
            'confidence' => 'int',
            'checked_at' => 'datetime',
        ];
    }

    public function settlement()
    {
        return $this->belongsTo(Settlement::class, 'settlement_ref', 'ref');
    }
}
