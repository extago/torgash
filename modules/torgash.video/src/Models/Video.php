<?php

namespace Torgash\Video\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Torgash\Media\Gallery;
use Torgash\Video\Sources\File;
use Torgash\Video\Sources\Sources;
use Torgash\Video\Sources\VideoSource;

/**
 * One video of one owner. Its poster is a media-library picture of its own
 * (`poster`), with the gallery's thumbnail sizes, so it lives on whichever
 * disk the shop's pictures live on and goes when the video goes.
 */
class Video extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia;

    public const POSTER = 'poster';

    protected $table = 'm_video_videos';

    protected $guarded = [];

    public static function auditLabel(): string
    {
        return 'Video';
    }

    /**
     * An uploaded file goes with its row — the whole `videos/<owner>/<id>/`
     * folder, so nothing a later step puts beside it (a smaller copy, a
     * poster frame) is left behind either.
     */
    protected static function booted(): void
    {
        static::deleted(function (Video $video) {
            $ref = (array) $video->ref;

            if ($video->source === File::KEY && ! empty($ref['path'])) {
                Storage::disk((string) ($ref['disk'] ?? 'public'))->deleteDirectory(dirname((string) $ref['path']));
            }
        });
    }

    /** Uploaded by the shop rather than played from a platform. */
    public function isFile(): bool
    {
        return $this->source === File::KEY;
    }

    /** ffmpeg has it in hand (queued or working) — PLAN §50, step 4. */
    public function preparing(): bool
    {
        return $this->isFile() && in_array($this->ref['state'] ?? null, ['queued', 'working'], true);
    }

    protected function casts(): array
    {
        return [
            'ref' => 'array',
            'title' => 'array',
            'duration' => 'integer',
            'position' => 'integer',
        ];
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::POSTER)->singleFile();
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        Gallery::conversions($this, $media);
    }

    public function scopeOwnedBy(Builder $query, string $ownerType, int $ownerId): Builder
    {
        return $query->where('owner_type', $ownerType)->where('owner_id', $ownerId)->orderBy('position')->orderBy('id');
    }

    /** The platform it plays on — null when the module that taught it is gone. */
    public function source(): ?VideoSource
    {
        return app(Sources::class)->get($this->source);
    }

    public function poster(): ?Media
    {
        return $this->getFirstMedia(self::POSTER);
    }

    public function posterUrl(int $minWidth = 0): ?string
    {
        $poster = $this->poster();

        return $poster ? Gallery::thumbUrl($poster, $minWidth) : null;
    }

    /** The player's address, ready to start: it is only ever loaded by a press of "play". */
    public function playerUrl(): ?string
    {
        return $this->source()?->playerUrl((array) $this->ref);
    }

    /** Where the video plays — "YouTube", "rutube.ru" — said beside the play button before it loads. */
    public function platform(): string
    {
        return (string) $this->source()?->platform((array) $this->ref);
    }
}
