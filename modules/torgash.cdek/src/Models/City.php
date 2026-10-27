<?php

namespace Torgash\Cdek\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A row of CDEK's city directory, keyed by the carrier's integer code.
 */
class City extends Model
{
    protected $table = 'm_cdek_cities';

    protected $primaryKey = 'code';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'CDEK city';
    }

    protected function casts(): array
    {
        return [
            'code' => 'int',
            'region_code' => 'int',
            'latitude' => 'float',
            'longitude' => 'float',
            'synced_at' => 'datetime',
        ];
    }

    /** "Новосибирск, Новосибирская область" — what a picker shows. */
    public function label(): string
    {
        return implode(', ', array_filter([$this->name, $this->sub_region, $this->region]));
    }
}
