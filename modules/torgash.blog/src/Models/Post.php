<?php

namespace Torgash\Blog\Models;

use App\Core\Seo\HasPageMeta;
use App\Core\Seo\PageSubject;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Torgash\Catalog\Models\Product;
use Torgash\Media\Gallery;

/**
 * An article (PLAN §47). Its cover is the first picture of its gallery (the
 * media module's own editor); pictures inside the text are the `content`
 * collection, as a product's description has.
 *
 * `status` is draft / published / hidden — hidden is published at its address
 * and in no feed, for a post a campaign links to. A published article with a
 * `published_at` still ahead is SCHEDULED: nothing lists or opens it until
 * then, and nothing has to run for it to appear. Its address never depends on
 * a rubric, so moving it between rubrics breaks no link; changing the address
 * itself leaves a 301 (core's redirects).
 */
class Post extends Model implements HasMedia, PageSubject
{
    use HasPageMeta, HasTranslations, InteractsWithMedia;

    public const PAGE_META_KIND = 'blog_post';

    public const DRAFT = 'draft';

    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    public const STATUSES = [self::DRAFT, self::PUBLISHED, self::HIDDEN];

    protected $table = 'm_blog_posts';

    protected $guarded = [];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    public static function auditLabel(): string
    {
        return 'Blog article';
    }

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'lead' => 'array',
            'body' => 'array',
            'meta_title' => 'array',
            'meta_description' => 'array',
            'h1' => 'array',
            'noindex' => 'bool',
            'comments_enabled' => 'bool',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Post $post) {
            $post->reading_minutes = self::readingMinutes($post->body);

            if ($post->status !== self::DRAFT && ! $post->published_at) {
                $post->published_at = now();
            }
        });

        static::saved(function (Post $post) {
            $was = $post->getOriginal('slug');

            if ($post->wasChanged('slug') && $was && $was !== $post->slug) {
                app(\App\Core\Seo\RedirectManager::class)->recordChange('/blog/'.$was, '/blog/'.$post->slug);
            }
        });
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        Gallery::conversions($this, $media);
    }

    public function rubrics(): BelongsToMany
    {
        return $this->belongsToMany(Rubric::class, 'm_blog_post_rubric', 'post_id', 'rubric_id')
            ->withPivot('position')->orderBy('m_blog_post_rubric.position');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'm_blog_post_products', 'post_id', 'product_id')
            ->withPivot('position')->orderBy('m_blog_post_products.position');
    }

    /** In a feed: published, its moment come. */
    public function scopeListed(Builder $query): Builder
    {
        return $query->where('status', self::PUBLISHED)->where('published_at', '<=', now());
    }

    /** By its address: published or hidden, its moment come. */
    public function scopeReachable(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PUBLISHED, self::HIDDEN])->where('published_at', '<=', now());
    }

    public function isReachable(): bool
    {
        return in_array($this->status, [self::PUBLISHED, self::HIDDEN], true) && $this->published_at && $this->published_at->isPast();
    }

    public function isScheduled(): bool
    {
        return $this->status !== self::DRAFT && $this->published_at && $this->published_at->isFuture();
    }

    public function url(): string
    {
        return route('blog.post', $this->slug);
    }

    public function coverUrl(int $minWidth = 0): ?string
    {
        $media = $this->getFirstMedia('gallery');

        return $media ? ($minWidth > 0 ? Gallery::thumbUrl($media, $minWidth) : $media->getUrl()) : null;
    }

    /**
     * Minutes to read, from the words of the text in its longest language —
     * about 180 words a minute, never less than one.
     *
     * @param  array<string, string>|null  $body
     */
    public static function readingMinutes(?array $body): int
    {
        $words = 0;

        foreach ((array) $body as $text) {
            $plain = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $text))) ?? '');
            $words = max($words, $plain === '' ? 0 : count(preg_split('/\s+/u', $plain)));
        }

        return max(1, (int) ceil($words / 180));
    }
}
