<?php

namespace Torgash\Pages\Models;

use App\Core\Display\DisplayRules;
use App\Core\Seo\HasPageMeta;
use App\Core\Seo\PageSubject;
use App\Core\Support\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Torgash\Pages\PageTree;

/**
 * One static page. See the migration for what this deliberately is not.
 */
/**
 * HasMedia for the pictures INSIDE its text (a `content` collection, no
 * gallery, no thumbnails): they die with the page, as a product's do. The
 * library is a composer dependency, not a module; the upload button exists
 * only while torgash.media is installed and has answered `media.owners`.
 */
class Page extends Model implements HasMedia, PageSubject
{
    use HasPageMeta, HasTranslations, InteractsWithMedia;

    public const PAGE_META_KIND = 'page';

    protected $table = 'm_pages_pages';

    protected $guarded = [];

    /** A change to it can be put back from the audit trail (Torgash\Guard\Audit\Revert): it is content, saved by its own model. */
    public static function auditRevertible(): bool
    {
        return true;
    }

    protected function casts(): array
    {
        return [
            'title' => 'array',
            'body' => 'array',
            'page_title' => 'array',
            'meta_description' => 'array',
            'h1' => 'array',
            'noindex' => 'bool',
            'enabled' => 'bool',
            'in_sitemap' => 'bool',
            'show_section_nav' => 'bool',
            'rules' => 'array',
        ];
    }

    /**
     * The address is derived, never typed straight in: `path` is the parent's
     * path plus this page's slug, recomputed on every save so an import, a
     * seeder and the admin form all produce the same answer.
     *
     * And when it MOVES, the old address becomes a 301 and every descendant
     * follows — renaming a section must not quietly break the ten pages under
     * it. That is the whole reason the path is a column.
     */
    protected static function booted(): void
    {
        static::saving(function (Page $page) {
            PageTree::assertNoCycle($page->id, $page->parent_id);
            $page->path = PageTree::pathFor($page);
        });

        static::saved(function (Page $page) {
            if (! $page->wasChanged('path')) {
                return;
            }

            $previous = $page->getOriginal('path');

            if ($previous && $previous !== $page->path) {
                PageTree::redirects()->recordChange('/'.$previous, '/'.$page->path);
            }

            PageTree::syncDescendants($page);
        });

        // A deleted section does not take its children's addresses with it:
        // they move up into its place, which re-addresses them through the
        // same path and leaves their own redirects behind.
        static::deleting(function (Page $page) {
            foreach ($page->children as $child) {
                $child->parent_id = $page->parent_id;
                $child->save();
            }
        });
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('position')->orderBy('id');
    }

    /** Its own address, in whichever language shape the current page is in. */
    public function url(): string
    {
        return route('pages.page', $this->path);
    }

    /**
     * From the root of the section down to (but not including) this page —
     * the breadcrumbs. Walks up by parent_id, which is at most a few rows and
     * always fewer than the section has pages.
     *
     * @return list<Page>
     */
    public function ancestors(): array
    {
        $chain = [];
        $cursor = $this->parent;

        while ($cursor) {
            array_unshift($chain, $cursor);
            $cursor = $cursor->parent;
        }

        return $chain;
    }

    /** The top of the section this page belongs to — itself, if it is the top. */
    public function sectionRoot(): self
    {
        $ancestors = $this->ancestors();

        return $ancestors[0] ?? $this;
    }

    /**
     * The section's own navigation: the root's published children, each with
     * its published children. Two levels, because a third one in a sidebar is
     * a menu nobody reads — and a shop that needs more has the menu module.
     *
     * @return Collection<int, Page>
     */
    public function sectionPages(): Collection
    {
        return $this->sectionRoot()
            ->children()
            ->published()
            ->with(['children' => fn ($q) => $q->where('enabled', true)])
            ->get()
            ->filter(fn (Page $page) => $page->isVisible())
            ->values();
    }

    /** Is this page part of a section worth drawing a navigation for? */
    public function hasSection(): bool
    {
        return $this->show_section_nav && $this->sectionPages()->isNotEmpty();
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * Is this page for HERE — this storefront, this language, this visitor?
     * Core's engine, the same call a block makes; no rules means everywhere.
     *
     * @param  array<string, mixed>  $context
     */
    public function isVisible(array $context = []): bool
    {
        return app(DisplayRules::class)->passes(
            (array) ($this->rules ?? []),
            $context,
            (string) ($this->rules_match ?: DisplayRules::MATCH_ALL),
        );
    }

    /** The <title>, falling back to the page's own heading. */
    public function pageTitle(?string $locale = null): string
    {
        return $this->t('page_title', $locale) ?: $this->t('title', $locale);
    }

    /**
     * Its own SEO fields, mapped onto the names core reads them by.
     *
     * `page_title` rather than `meta_title` because that is what the column has
     * been called since pages existed, and a rename would rewrite every page a
     * shop has written to gain one word of consistency. This method is the
     * whole cost of that difference.
     *
     * @return array{title: ?string, description: ?string, h1: ?string, noindex: bool, canonical: ?string}
     */
    public function pageMeta(): array
    {
        return [
            'title' => $this->pageMetaText('page_title'),
            'description' => $this->pageMetaText('meta_description'),
            'h1' => $this->pageMetaText('h1'),
            'noindex' => (bool) $this->noindex,
            'canonical' => $this->canonical_url ?: null,
        ];
    }

    /**
     * The body as it is shown: admin-authored HTML with placeholders expanded,
     * so `{callback_link}` and the icon tokens work here exactly as they do in
     * an HTML block. The page itself goes into the context, which is what lets
     * a module register a placeholder that answers about the page it is on.
     */
    public function renderedBody(?string $locale = null): string
    {
        $body = (string) $this->t('body', $locale);

        // Cleaned before the placeholders are expanded: `{form:…}` puts the
        // engine's own form in, which is not the staff's to write.
        return $body === '' ? '' : expand_placeholders(app(\App\Core\Content\SafeHtml::class)->clean($body), ['page' => $this]);
    }
}
