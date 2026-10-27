<?php

namespace Torgash\Banners\Models;

use App\Core\Display\DisplayRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Torgash\Banners\Fields;
use Torgash\Media\Gallery;

/**
 * One banner (PLAN §51): the values of its template's fields — words,
 * pictures (media collections `f_<key>`), a link, a colour. Its own dates and
 * display rules decide whether it is shown; the sliders it stands in decide
 * where; its template decides how it looks.
 */
class Banner extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'm_banners_banners';

    protected $guarded = [];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    public static function auditLabel(): string
    {
        return 'Banner';
    }

    protected function casts(): array
    {
        return [
            'values' => 'array',
            'rules' => 'array',
            'enabled' => 'boolean',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
        ];
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        // A video field's file is played as it is: no thumbnails made of it.
        if ($media && str_starts_with((string) $media->mime_type, 'video/')) {
            return;
        }

        Gallery::conversions($this, $media);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class, 'template_id');
    }

    public function sliders(): BelongsToMany
    {
        return $this->belongsToMany(Slider::class, 'm_banners_slider_banner', 'banner_id', 'slider_id')->withPivot('position');
    }

    /** Switched on and inside its dates. The display rules are asked per page (`isVisible()`). */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('enabled', true)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    public function isVisible(array $context = []): bool
    {
        return app(DisplayRules::class)->passes($this->rules ?? [], $context, $this->rules_match ?? DisplayRules::MATCH_ALL);
    }

    /** off / scheduled / ended / running — what the list's badge and tabs say. */
    public function state(): string
    {
        return match (true) {
            ! $this->enabled => 'off',
            $this->starts_at !== null && $this->starts_at->isFuture() => 'scheduled',
            $this->ends_at !== null && ! $this->ends_at->isFuture() => 'ended',
            default => 'running',
        };
    }

    /** The first picture any of its fields holds — the list's thumbnail. */
    public function thumbUrl(): ?string
    {
        $media = $this->media->first(fn (Media $media) => str_starts_with($media->collection_name, 'f_') && ! str_starts_with((string) $media->mime_type, 'video/'));

        return $media ? Gallery::thumbUrl($media, 200) : null;
    }

    /** A picture field's address, for the admin's form. */
    public function pictureUrl(string $key, int $minWidth = 400): ?string
    {
        $media = $this->getFirstMedia(Fields::collection($key));

        return $media ? Gallery::thumbUrl($media, $minWidth) : null;
    }
}
