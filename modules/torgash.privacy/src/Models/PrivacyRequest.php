<?php

namespace Torgash\Privacy\Models;

use App\Core\Privacy\Subject;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person asking for their data (PLAN §49) — to download it or to have it
 * erased. A manager carries it out; nothing is erased by the asking alone,
 * because the person may have an unpaid order or an open return.
 */
class PrivacyRequest extends Model
{
    public const EXPORT = 'export';

    public const ERASE = 'erase';

    public const NEW = 'new';

    public const DONE = 'done';

    public const REJECTED = 'rejected';

    protected $table = 'm_privacy_requests';

    protected $guarded = [];

    public static function auditLabel(): string
    {
        return 'Personal data request';
    }

    protected function casts(): array
    {
        return [
            'report' => 'array',
            'due_at' => 'datetime',
            'done_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::NEW);
    }

    /** Whose data — the account while it exists, and the contacts as written. */
    public function subject(): Subject
    {
        // The account, plus the contacts the request names — not the
        // account's own unconfirmed ones (see Requests::open).
        return new Subject(
            $this->user,
            array_values(array_filter([$this->email ? mb_strtolower(trim($this->email)) : null])),
            array_values(array_filter([$this->phone ? preg_replace('/\D/', '', $this->phone) : null])),
        );
    }

    public function isOverdue(): bool
    {
        return $this->status === self::NEW && $this->due_at?->isPast();
    }

    public function typeLabel(): string
    {
        return $this->type === self::ERASE ? __('Erase my data') : __('Download my data');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::DONE => __('Done'),
            self::REJECTED => __('Refused'),
            default => __('New'),
        };
    }
}
