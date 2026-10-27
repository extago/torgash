<?php

namespace Torgash\Guard\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What we know about the looking: when, how long, how many, and whether it
 * worked at all.
 *
 * @property string $key
 * @property int $duration_ms
 * @property int $violations
 */
class CheckState extends Model
{
    protected $table = 'm_guard_checks';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public $timestamps = false;

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Check run';
    }

    protected function casts(): array
    {
        return [
            'last_run_at' => 'datetime',
            'truncated' => 'bool',
        ];
    }
}
