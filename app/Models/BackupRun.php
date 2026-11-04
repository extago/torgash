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
        'files_since' => 'datetime',
    ];

    /**
     * Holds only the files changed since `files_since` (PLAN §67.10): the
     * database whole, the files those added or changed after the full
     * backup it leans on (`base`) started. Restoring takes that one first.
     */
    public function isDifferential(): bool
    {
        return $this->files_since !== null;
    }

    /** The full backup a differential one leans on; null once it is deleted. */
    public function base(): BelongsTo
    {
        return $this->belongsTo(self::class, 'base_run_id');
    }

    /** Whether the full backup it leans on is still kept somewhere. */
    public function baseKept(): bool
    {
        return $this->base_run_id !== null
            && self::query()->whereKey($this->base_run_id)->whereHas('storedCopies')->exists();
    }

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
