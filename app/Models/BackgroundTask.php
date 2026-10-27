<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admin-visible run of a background job. Written by App\Core\Tasks\TrackedJob;
 * read by the Background tasks screen.
 */
class BackgroundTask extends Model
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    /** Stopped on purpose — by the admin, or because its worker was gone. */
    public const CANCELLED = 'cancelled';

    /** Statuses a task never leaves: what "Clear finished" and the sweep remove. */
    public const FINISHED = [self::DONE, self::FAILED, self::CANCELLED];

    /**
     * How long a RUNNING row may go without a write before it is taken for
     * dead. A job reports progress at most every half second, and the worker
     * kills one after TrackedJob::$timeout (an hour) — so an hour and a few
     * minutes of silence means nobody is running it any more, and the row
     * would otherwise say "running" for ever (and block `startIfIdle()`).
     */
    public const STALE_AFTER_SECONDS = 3900;

    protected $guarded = [];

    protected $casts = [
        'result' => 'array',
        'message_params' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'cancel_requested_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The job's own status line, translated for whoever is reading the list. */
    public function messageText(): ?string
    {
        return $this->message ? __($this->message, $this->message_params ?? []) : null;
    }

    /**
     * What the job wanted the admin to know besides its status line — an
     * import's "line 12: category created" — as `result.notes`, a list of
     * `{message, params}` in English, translated here for whoever reads it.
     * A param may itself be a `{message, params}` — a reason inside the
     * sentence ("column Price: \"abc\" is not a number") — and is translated too.
     *
     * @return list<string>
     */
    public function notes(): array
    {
        return array_values(array_filter(array_map(
            fn ($note) => is_array($note) && isset($note['message']) ? self::sentence($note) : null,
            (array) ($this->result['notes'] ?? []),
        )));
    }

    protected static function sentence(array $note): string
    {
        $params = array_map(
            fn ($param) => is_array($param) && isset($param['message']) ? self::sentence($param) : $param,
            (array) ($note['params'] ?? []),
        );

        return __((string) $note['message'], $params);
    }

    /** How many notes there were, the ones not kept included. */
    public function noteCount(): int
    {
        return max((int) ($this->result['note_count'] ?? 0), count((array) ($this->result['notes'] ?? [])));
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::QUEUED, self::RUNNING], true);
    }

    /** Running by its row, but nothing has written to it for longer than any job may run. */
    public function isStale(): bool
    {
        return $this->status === self::RUNNING
            && ($this->updated_at ?? $this->created_at)?->lt(now()->subSeconds(self::STALE_AFTER_SECONDS));
    }

    /** Where the list files it: in progress / done / failed (a cancelled one counts as finished, not as failed). */
    public function tab(): string
    {
        return match (true) {
            $this->isStale(), $this->status === self::FAILED => 'failed',
            $this->isActive() => 'active',
            default => 'done',
        };
    }

    /** Wall-clock time as words ("2 min 5 s"), or null before it started. */
    public function duration(): ?string
    {
        $seconds = $this->seconds();

        if ($seconds === null) {
            return null;
        }

        return $seconds < 1
            ? __('under a second')
            : \Carbon\CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }

    /** Percent complete, or null when the job never announced a total. */
    public function percent(): ?int
    {
        if (! $this->total) {
            return $this->status === self::DONE ? 100 : null;
        }

        return (int) min(100, round($this->progress / $this->total * 100));
    }

    /** Wall-clock seconds the job ran for, or has been running. */
    public function seconds(): ?int
    {
        if (! $this->started_at) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), absolute: true);
    }
}
