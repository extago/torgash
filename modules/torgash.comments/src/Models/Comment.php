<?php

namespace Torgash\Comments\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Torgash\Comments\CommentTargets;

class Comment extends Model
{
    protected $table = 'm_comments_comments';

    protected $guarded = [];

    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    /** The whole vocabulary — "have we let this through" is the same question in every shop. */
    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED];

    protected function casts(): array
    {
        return [
            'is_staff' => 'bool',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED);
    }

    /** @param  string|int  $targetId */
    public function scopeFor(Builder $query, string $targetType, $targetId): Builder
    {
        return $query->where('target_type', $targetType)->where('target_id', (int) $targetId);
    }

    /** What was commented on, resolved through the registry. */
    public function targetTitle(): string
    {
        return CommentTargets::title($this->target_type, (int) $this->target_id);
    }

    public function targetUrl(): ?string
    {
        return CommentTargets::url($this->target_type, (int) $this->target_id);
    }
}
