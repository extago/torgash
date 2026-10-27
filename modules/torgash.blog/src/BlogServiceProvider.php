<?php

namespace Torgash\Blog;

use App\Core\Storefronts\StorefrontRoutes;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Torgash\Blog\Models\Post;
use Torgash\Blog\Models\Rubric;

/**
 * The blog (PLAN §47). Everything it gives other modules is through their
 * own seams: the media module keeps its pictures, the menus offer its pages,
 * the sitemap, the SEO templates, the live search and `/llms.txt` list its
 * articles, and `torgash.comments` — when it is here — takes comments on an
 * article.
 */
class BlogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Article::class);
        $this->app->singleton(Likes::class);
    }

    public function boot(): void
    {
        // Its part of the demo shop (PLAN §55.3), after the catalogue's products.
        hook_listen('core.demo.parts', fn (array $parts) => $parts + [
            'blog' => ['label' => 'Blog', 'after' => ['catalog'], 'install' => fn (\App\Core\Demo\DemoPack $pack) => app(Demo\BlogDemo::class)->install($pack)],
        ]);

        StorefrontRoutes::group(dirname(__DIR__).'/routes.php');

        hook_listen('core.theme.preview_pages', fn (array $pages) => [...$pages,
            ['label' => 'Blog', 'icon' => 'newspaper', 'path' => route('blog.index', [], false), 'position' => 60],
        ]);

        // The latest articles as a block on any page the shop arranges — the
        // home page's "news" — when torgash.blocks is installed.
        if (class_exists(\Torgash\Blocks\BlockTypes::class)) {
            \Torgash\Blocks\BlockTypes::register('blog_posts', [
                'description' => 'The latest articles of the blog: one large beside a few small, or a row of cards.',
                'fits' => ['wide'],
                'label' => 'Latest articles',
                'icon' => 'newspaper',
                'view' => 'torgash.blog::blocks.posts',
                'schema' => [
                    ['key' => 'heading', 'type' => 'text', 'translatable' => true, 'label' => 'Heading', 'help' => 'Optional.'],
                    ['key' => 'rubric', 'type' => 'select', 'default' => '', 'label' => 'Rubric',
                        'options' => fn () => [
                            ['value' => '', 'label' => 'All articles'],
                            ...\Torgash\Blog\Models\Rubric::shown()->get()
                                ->map(fn ($rubric) => ['value' => (string) $rubric->id, 'label' => $rubric->t('name')])->all(),
                        ]],
                    ['key' => 'layout', 'type' => 'select', 'default' => 'featured', 'label' => 'Layout', 'options' => [
                        ['value' => 'featured', 'label' => 'The newest large, the rest small beside it'],
                        ['value' => 'row', 'label' => 'A row of equal cards'],
                    ]],
                    ['key' => 'limit', 'type' => 'number', 'default' => 5, 'min' => 1, 'max' => 12, 'label' => 'How many articles'],
                ],
            ]);
        }

        Route::middleware(['web', 'auth', 'can:blog.manage'])
            ->prefix('admin/blog')
            ->name('admin.blog.')
            ->group(dirname(__DIR__).'/routes-admin.php');

        // The cover is the first picture of the article's gallery; pictures
        // inside the text are its `content` collection.
        hook_listen('media.owners', fn (array $context) => $context['owners']->register('blog_post', Post::class, 'blog.manage', content: true, single: true));
        // Videos in an article, when torgash.video is on: its list on the
        // form, `{video:ID}` in the text. Only this module's hook names go
        // over — the video module never names the blog.
        hook_listen('video.owners', fn (array $context) => $context['owners']->register('blog_post', Post::class, 'blog.manage', [
            'form' => 'blog.post.form_sections',
            'saved' => 'blog.post.saved',
            'context' => 'post',
        ]));

        $this->navigation();
        $this->seo();
        $this->search();

        hook_listen('comments.targets', fn () => \Torgash\Comments\CommentTargets::register('blog_post', [
            'label' => 'Blog articles',
            'context' => 'post',
            'resolve' => fn (int $id) => Post::find($id),
            'url' => fn (Post $post) => $post->url(),
            'title' => fn (Post $post) => $post->t('title') ?: $post->slug,
        ]));
    }

    protected function navigation(): void
    {
        hook_listen('menus.pages', function (array $pages) {
            $pages['route:blog.index'] = [
                'label' => 'Blog',
                'url' => fn () => route('blog.index'),
                'group' => 'Blog',
                'icon' => 'newspaper',
            ];

            foreach (Rubric::shown()->get() as $rubric) {
                $pages['blog_rubric:'.$rubric->id] = [
                    'label' => $rubric->name,
                    'url' => fn () => $rubric->url(),
                    'group' => 'Blog',
                    'icon' => $rubric->icon,
                ];
            }

            return $pages;
        });
    }

    protected function seo(): void
    {
        hook_listen('catalog.sitemap.urls', function (array $urls) {
            $urls[] = ['loc' => route('blog.index'), 'changefreq' => 'daily', 'priority' => '0.6'];

            foreach (Rubric::shown()->where('noindex', false)->get() as $rubric) {
                $urls[] = ['loc' => $rubric->url(), 'changefreq' => 'weekly', 'priority' => '0.5'];
            }

            foreach (Post::listed()->where('noindex', false)->orderByDesc('published_at')->get() as $post) {
                $urls[] = ['loc' => $post->url(), 'lastmod' => $post->updated_at?->toAtomString(), 'changefreq' => 'monthly', 'priority' => '0.6'];
            }

            return $urls;
        });

        hook_listen('seo.subjects', fn (array $subjects) => $subjects + [
            'blog_post' => [
                'label' => 'Blog articles',
                'query' => fn () => Post::query()->orderByDesc('published_at'),
                'title' => fn (Post $post) => $post->t('title') ?: $post->slug,
                'url' => fn (Post $post) => $post->url(),
                'edit_url' => fn (Post $post) => route('admin.blog.edit', $post),
                'columns' => ['title' => 'meta_title', 'description' => 'meta_description', 'h1' => 'h1'],
            ],
            'blog_rubric' => [
                'label' => 'Blog rubrics',
                'query' => fn () => Rubric::query()->orderBy('sort'),
                'title' => fn (Rubric $rubric) => $rubric->t('name') ?: $rubric->slug,
                'url' => fn (Rubric $rubric) => $rubric->url(),
                'edit_url' => fn (Rubric $rubric) => route('admin.blog.rubrics.edit', $rubric),
                'columns' => ['title' => 'meta_title', 'description' => 'meta_description', 'h1' => 'h1'],
            ],
        ]);

        hook_listen('core.llms.sections', function (array $sections) {
            $posts = Post::listed()->orderByDesc('published_at')->limit(30)->get();

            if ($posts->isEmpty()) {
                return $sections;
            }

            $sections[] = [
                'title' => __('Blog articles'),
                'position' => 40,
                'text' => __('Each of these is also available as text at the same address plus .md.'),
                'items' => $posts->map(fn (Post $post) => ['label' => $post->t('title') ?: $post->slug, 'url' => $post->url().'.md'])->values()->all(),
            ];

            return $sections;
        });
    }

    /** Articles in the live window, when the visitor searches the site. */
    protected function search(): void
    {
        hook_listen('search.suggest.sections', function (array $sections, array $context) {
            $words = array_values(array_filter((array) ($context['words'] ?? []), fn ($w) => mb_strlen($w) >= 2));

            if ($words === []) {
                return $sections;
            }

            $query = Post::listed();
            foreach ($words as $word) {
                $query->whereRaw(
                    'exists (select 1 from jsonb_each_text(title) t(k, v) where lower(t.v) like ?)',
                    ['%'.addcslashes(mb_strtolower($word), '\\%_').'%'],
                );
            }

            $items = $query->orderByDesc('published_at')->limit(5)->get()
                ->map(fn (Post $post) => ['label' => $post->t('title'), 'url' => $post->url(), 'hint' => $post->published_at?->translatedFormat('j F Y')])
                ->values()->all();

            return $items === [] ? $sections : [...$sections, ['title' => __('Blog'), 'items' => $items]];
        });
    }
}
