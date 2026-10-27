<?php

namespace Torgash\Blog\Models;

use App\Core\Seo\HasPageMeta;
use App\Core\Seo\PageSubject;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A rubric of the blog (PLAN §47): the coloured plate over an article's title
 * and a feed of its own. A flat list — the owner's decision: a blog needs no
 * tree of sections, and tags are gone altogether.
 */
class Rubric extends Model implements PageSubject
{
    use HasPageMeta, HasTranslations;

    public const PAGE_META_KIND = 'blog_rubric';

    protected $table = 'm_blog_rubrics';

    protected $guarded = [];

    public static function auditLabel(): string
    {
        return 'Blog rubric';
    }

    protected function casts(): array
    {
        return [
            'name' => 'array',
            'description' => 'array',
            'meta_title' => 'array',
            'meta_description' => 'array',
            'h1' => 'array',
            'noindex' => 'bool',
            'enabled' => 'bool',
        ];
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'm_blog_post_rubric', 'rubric_id', 'post_id');
    }

    public function scopeShown(Builder $query): Builder
    {
        return $query->where('enabled', true)->orderBy('sort')->orderBy('id');
    }

    public function url(): string
    {
        return route('blog.rubric', $this->slug);
    }

    /** The plate's colour, a readable default when none was chosen. */
    public function colour(): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', (string) $this->color) ? $this->color : '#64748b';
    }
}
