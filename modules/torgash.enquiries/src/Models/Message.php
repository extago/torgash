<?php

namespace Torgash\Enquiries\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Torgash\Media\Gallery;

/**
 * One turn in the conversation on an enquiry.
 *
 * @property bool $is_staff
 * @property string $body
 */
class Message extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'm_enquiries_messages';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_staff' => 'boolean',
        ];
    }

    /**
     * Thumbnails for what people attach, from the media module's own settings —
     * the same sizes a product photo gets, because a photograph is a photograph.
     * Registered on both halves of a conversation: the enquiry carries what came
     * with the form, a message what came with a reply.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        Gallery::conversions($this, $media);
    }

    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Who to print above the message.
     *
     * The stored snapshot first — that is the point of storing it — then the
     * enquiry's own name for a customer who wrote before this table existed,
     * and only then a label. Never the live account name: a renamed manager
     * must not rewrite what somebody read last month.
     */
    public function authorName(): string
    {
        return trim((string) $this->author_name)
            ?: trim((string) ($this->is_staff ? '' : $this->enquiry?->name))
            ?: ($this->is_staff ? __('The shop') : __('Customer'));
    }
}
