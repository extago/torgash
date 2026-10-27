<?php

namespace Torgash\Model3d\Models;

use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Torgash\Media\Gallery;

/**
 * One 3D model of one owner: a .glb on the public disk and a poster of its
 * own (`poster`) for the strip. See the migration.
 */
class Model3d extends Model implements HasMedia
{
    use HasTranslations, InteractsWithMedia;

    public const DISK = 'public';

    public const DIRECTORY = 'model3d';

    public const POSTER = 'poster';

    protected $table = 'm_model3d_models';

    protected $guarded = [];

    public static function auditLabel(): string
    {
        return '3D model';
    }

    /** The file goes with its row; the poster goes with it as the media library's own. */
    protected static function booted(): void
    {
        static::deleted(function (Model3d $model) {
            Storage::disk(self::DISK)->delete($model->path);
        });
    }

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'size' => 'int',
            'position' => 'int',
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

    public function url(): string
    {
        return Storage::disk(self::DISK)->url($this->path);
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
}
