<?php

namespace Torgash\Catalog\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The shop's word about one availability status — a rename and a weight for a
 * status registered in code, or the whole definition of one the shop invented.
 * Which of the two a row is follows from the registry, never from a column:
 * see {@see \Torgash\Catalog\Stock\AvailabilityStatuses::all()}.
 */
class AvailabilityStatus extends Model
{
    protected $table = 'm_catalog_availability_statuses';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'label' => 'array',
            'orderable' => 'bool',
            'tracks_stock' => 'bool',
            'allow_negative' => 'bool',
            'sort_weight' => 'int',
        ];
    }
}
