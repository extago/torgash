<?php

namespace Torgash\Newsletter\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A campaign: one letter to a segment of the subscribers (PLAN §57.4).
 *
 * draft → scheduled (a time is set, or "send now") → sending (the deliveries
 * are made, the sender walks them) → sent; `cancelled` stops a scheduled or a
 * sending one where it is. Only a draft or a scheduled campaign may be
 * edited — a letter half the list has already read is not rewritten for the
 * other half. Pictures inside the letter are the media module's `content`
 * collection (owner `newsletter_campaign`), as in a product's description.
 */
class Campaign extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia;

    public const DRAFT = 'draft';

    public const SCHEDULED = 'scheduled';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const CANCELLED = 'cancelled';

    protected $table = 'm_newsletter_campaigns';

    protected $guarded = [];

    protected $attributes = [
        'status' => self::DRAFT,
    ];

    protected function casts(): array
    {
        return [
            'subject' => 'array',
            'preheader' => 'array',
            'heading' => 'array',
            'body' => 'array',
            'segment' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** The media module is optional: without it a letter simply has no pictures of its own. */
    public function registerMediaConversions(?Media $media = null): void
    {
        if (class_exists(\Torgash\Media\Gallery::class)) {
            \Torgash\Media\Gallery::conversions($this, $media);
        }
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    public function links(): HasMany
    {
        return $this->hasMany(Link::class);
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::SCHEDULED], true);
    }

    /** A share of the letters that went out, in whole percent; null before any did. */
    public function rate(string $counter): ?int
    {
        return $this->sent > 0 ? (int) round(100 * (int) $this->{$counter} / $this->sent) : null;
    }
}
