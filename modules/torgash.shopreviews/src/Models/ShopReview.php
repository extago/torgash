<?php

namespace Torgash\ShopReviews\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What one customer thought of buying here, with the shop's answer on the row.
 */
class ShopReview extends Model
{
    public const PENDING = 'pending';

    public const PUBLISHED = 'published';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING, self::PUBLISHED, self::REJECTED];

    protected $table = 'm_shopreviews_reviews';

    protected $fillable = [
        'user_id', 'author_name', 'city', 'email', 'body', 'pros', 'cons', 'rating',
        'recommend', 'will_return', 'status', 'verified', 'source',
        'reply', 'reply_author', 'replied_by', 'replied_at',
        'storefront_id', 'locale', 'ip', 'created_at',
    ];

    protected $hidden = ['email', 'ip'];

    protected function casts(): array
    {
        return [
            'user_id' => 'int',
            'rating' => 'float',
            'recommend' => 'bool',
            'will_return' => 'bool',
            'verified' => 'bool',
            'replied_at' => 'datetime',
        ];
    }

    /** What the audit trail calls one of these (translated through the lang files). */
    public static function auditLabel(): string
    {
        return 'Shop review';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class, 'review_id');
    }

    /** Published, on this storefront or on all of them. */
    public function scopeShown(Builder $query): Builder
    {
        $storefront = current_storefront()?->id;

        return $query->where('status', self::PUBLISHED)
            ->where(fn ($q) => $q->whereNull('storefront_id')->when($storefront, fn ($w) => $w->orWhere('storefront_id', $storefront)));
    }

    /** Whole stars, as they are drawn: 4.6 is four and a bit, drawn as five. */
    public function stars(): int
    {
        return (int) max(1, min(5, round((float) $this->rating)));
    }

    /** The letter in the round mark beside the name. */
    public function initial(): string
    {
        return mb_strtoupper(mb_substr(trim((string) $this->author_name) ?: '?', 0, 1));
    }

    /** A hue per name, so neighbours' marks differ and one person's stays the same. */
    public function hue(): int
    {
        return crc32((string) $this->author_name) % 360;
    }
}
