<?php

namespace Torgash\Points\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One rule of the points programme (PLAN §74): which goods (and whose
 * basket), what they earn, whether points pay for them. The list is tried in
 * `position` order — see {@see \Torgash\Points\Rules}.
 */
class Rule extends Model
{
    protected $table = 'm_points_rules';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'enabled' => 'boolean',
            'earn_value' => 'float',
            'spend_percent' => 'integer',
            'position' => 'integer',
        ];
    }
}
