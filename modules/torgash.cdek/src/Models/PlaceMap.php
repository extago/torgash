<?php

namespace Torgash\Cdek\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The bridge: one of our place ids to one CDEK city. Its presence is what
 * makes CDEK an option for that place at all.
 */
class PlaceMap extends Model
{
    protected $table = 'm_cdek_place_map';

    protected $primaryKey = 'place_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'CDEK town match';
    }

    protected function casts(): array
    {
        return [
            'city_code' => 'int',
            'confidence' => 'int',
            'checked_at' => 'datetime',
        ];
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'city_code', 'code');
    }
}
