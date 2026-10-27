<?php

namespace Torgash\Reviews\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Torgash\Catalog\Models\Product;
use Torgash\Media\Gallery;

/**
 * One person's verdict on one product, plus the shop's answers under it.
 *
 * `parent_id` is what makes the shop's reply part of the review rather than a
 * second list somewhere: a public answer to a complaint is read WITH the
 * complaint or it is not read at all. A reply carries no rating and counts
 * towards nothing.
 */
class Review extends Model implements HasMedia
{
    use InteractsWithMedia;

    public const PENDING = 'pending';

    public const PUBLISHED = 'published';

    public const REJECTED = 'rejected';

    public const STATUSES = [self::PENDING, self::PUBLISHED, self::REJECTED];

    /** Would you recommend it — the question the mock-ups put under the stars. */
    public const RECOMMEND = ['yes', 'neutral', 'no'];

    protected $table = 'm_reviews_reviews';

    protected $fillable = [
        'product_id', 'parent_id', 'user_id', 'author_name', 'body', 'pros', 'cons',
        'rating', 'recommend', 'status', 'moderation_note', 'verified', 'is_staff',
        'storefront_id', 'locale',
    ];

    protected function casts(): array
    {
        return [
            'product_id' => 'int',
            'parent_id' => 'int',
            'user_id' => 'int',
            'rating' => 'float',
            'verified' => 'bool',
            'is_staff' => 'bool',
        ];
    }

    /**
     * Thumbnails for what people photograph, from the media module's own
     * settings — the same sizes a product photo gets, because a photograph is
     * a photograph.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        Gallery::conversions($this, $media);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(Rating::class, 'review_id');
    }

    /** Only the shop's answers: a customer arguing back belongs in an enquiry, where it is private and answerable. */
    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->where('status', self::PUBLISHED)->orderBy('id');
    }

    /** Reviews proper — never the replies, which share the two columns a review is found by. */
    public function scopeReviews(Builder $query): Builder
    {
        return $query->whereNull('parent_id');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED);
    }

    /** What the inbox opens on, the same idea as the enquiries queue: what nobody has looked at yet. */
    public function scopeWaiting(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function isReply(): bool
    {
        return $this->parent_id !== null;
    }

    /** The whole-star mark a row is counted under in the 1-5 spread. */
    public function stars(): int
    {
        return max(1, min(5, (int) round((float) $this->rating)));
    }
}
