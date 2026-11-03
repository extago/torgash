<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A run's file in one destination: where it lies, or why it is not there. */
class BackupCopy extends Model
{
    public const STORED = 'stored';

    public const FAILED = 'failed';

    public const DELETED = 'deleted';

    protected $guarded = ['id'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BackupRun::class, 'run_id');
    }

    public function destination(): BelongsTo
    {
        return $this->belongsTo(BackupDestination::class, 'destination_id');
    }
}
