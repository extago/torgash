<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One backup made (or being made) — the journal of PLAN §67. */
class BackupRun extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    /** Made, but at least one destination did not take it. */
    public const PARTIAL = 'partial';

    public const FAILED = 'failed';

    protected $guarded = ['id'];

    protected $casts = [
        'contents' => 'array',
        'pinned' => 'boolean',
        'encrypted' => 'boolean',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    /** Made "without an archive": its copies are folders. */
    public function isFolder(): bool
    {
        return $this->format === 'folder';
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(BackupPlan::class, 'plan_id');
    }

    public function copies(): HasMany
    {
        return $this->hasMany(BackupCopy::class, 'run_id');
    }

    public function storedCopies(): HasMany
    {
        return $this->copies()->where('status', BackupCopy::STORED);
    }

    public function finished(): bool
    {
        return in_array($this->status, [self::DONE, self::PARTIAL, self::FAILED], true);
    }
}
