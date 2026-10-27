<?php

namespace Torgash\Blog\Http;

use App\Core\Seo\AgentTraffic;
use App\Core\Seo\RedirectManager;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Torgash\Blog\Article;
use Torgash\Blog\Likes;
use Torgash\Blog\Models\Post;
use Torgash\Blog\Models\Rubric;
use Torgash\Blog\PostText;

/**
 * The blog on the storefront (PLAN §47): the feed, a rubric's feed, an
 * article, its text rendition and its like.
 *
 * A feed is ordinary pages that work with no script; "show more" asks the
 * next page over HTMX and gets only its cards, with the next "show more"
 * behind them.
 */
class BlogController extends Controller
{
    public function __construct(protected RedirectManager $redirects) {}

    public function index(Request $request)
    {
        page_meta()->set(['title' => __('Blog'), 'h1' => __('Blog'),
            'description' => __('Articles and news from :shop.', ['shop' => current_storefront()?->name])]);
        breadcrumbs()->add(__('Blog'));

        return $this->feed($request, Post::query(), null);
    }

    public function rubric(Request $request, string $slug)
    {
        $rubric = Rubric::where('slug', $slug)->where('enabled', true)->first();

        if (! $rubric) {
            return $this->redirectOr404('/blog/rubric/'.$slug);
        }

        page_meta()->set(['title' => $rubric->t('name'), 'h1' => $rubric->t('name'), 'description' => strip_tags((string) $rubric->t('description'))])->describe($rubric);
        breadcrumbs()->add(__('Blog'), route('blog.index'))->add((string) $rubric->t('name'));

        return $this->feed($request, Post::query()->whereHas('rubrics', fn (Builder $q) => $q->whereKey($rubric->id)), $rubric);
    }

    public function post(Request $request, string $slug, Article $article, Likes $likes)
    {
        $post = Post::reachable()->where('slug', $slug)->with(['rubrics' => fn ($q) => $q->where('enabled', true), 'media'])->first();

        if (! $post) {
            return $this->redirectOr404('/blog/'.$slug);
        }

        $this->countView($request, $post);
        $prepared = $article->prepare($post);

        page_meta()
            ->set([
                'title' => $post->t('title'),
                'h1' => $post->t('title'),
                'description' => strip_tags((string) $post->t('lead')),
                'type' => 'article',
                'image' => $post->coverUrl() ?? '',
            ])
            ->describe($post);

        breadcrumbs()->add(__('Blog'), route('blog.index'));
        if ($first = $post->rubrics->first()) {
            breadcrumbs()->add((string) $first->t('name'), $first->url());
        }
        breadcrumbs()->add((string) $post->t('title'));

        structured_data()->add('blog_post', array_filter([
            '@type' => 'BlogPosting',
            'headline' => $post->t('title'),
            'description' => strip_tags((string) $post->t('lead')) ?: null,
            'image' => $post->coverUrl(),
            'datePublished' => $post->published_at?->toIso8601String(),
            'dateModified' => $post->updated_at?->toIso8601String(),
            'author' => $post->author_name ? ['@type' => 'Person', 'name' => $post->author_name] : null,
            'publisher' => ['@id' => structured_data()->organisationId()],
            'mainEntityOfPage' => $post->url(),
        ]));

        $readAlso = max(0, (int) setting('torgash.blog', 'read_also', 3));
        $rubricIds = $post->rubrics->pluck('id');

        return view('torgash.blog::post', [
            'post' => $post,
            'html' => $prepared['html'],
            'contents' => $prepared['contents'],
            'liked' => $likes->has($request, $post),
            'products' => $post->products()->listed()->withCardData()->get(),
            'readAlso' => $readAlso === 0 ? collect() : Post::listed()
                ->whereKeyNot($post->id)
                ->when($rubricIds->isNotEmpty(), fn ($q) => $q->whereHas('rubrics', fn ($r) => $r->whereIn('m_blog_rubrics.id', $rubricIds)))
                ->with(['rubrics', 'media'])->orderByDesc('published_at')->limit($readAlso)->get(),
        ]);
    }

    public function text(Request $request, string $slug)
    {
        AgentTraffic::enforce($request);
        $post = Post::reachable()->where('slug', $slug)->first();

        abort_unless($post, 404);

        return response(PostText::render($post), 200)->header('Content-Type', 'text/plain; charset=utf-8');
    }

    /** The heart: pressed or taken back, answered with itself. */
    public function like(Request $request, string $slug, Likes $likes)
    {
        $post = Post::reachable()->where('slug', $slug)->firstOrFail();
        $liked = $likes->toggle($request, $post);

        // With no script the form posted the whole page: back to the article.
        if (! $request->header('HX-Request')) {
            return redirect()->to($post->url());
        }

        return view('torgash.blog::partials.like', ['post' => $post->fresh(), 'liked' => $liked]);
    }

    protected function feed(Request $request, Builder $query, ?Rubric $rubric)
    {
        $posts = within_pages($query->listed()->with(['rubrics' => fn ($q) => $q->where('enabled', true), 'media'])
            ->orderByDesc('published_at')->orderByDesc('id')
            ->paginate(max(1, (int) setting('torgash.blog', 'per_page', 12)))->withQueryString());

        // "Show more": the next page's cards only, and the next button.
        if ($request->header('HX-Request') && $request->boolean('more')) {
            return view('torgash.blog::partials.cards', ['posts' => $posts, 'more' => true]);
        }

        return view('torgash.blog::index', [
            'posts' => $posts,
            'rubric' => $rubric,
            // Counted as the feed lists them — what a reader will find there.
            'rubrics' => Rubric::shown()
                ->whereHas('posts', fn ($q) => $q->listed())
                ->withCount(['posts as listed_count' => fn ($q) => $q->listed()])
                ->get(),
            'allCount' => Post::query()->listed()->count(),
        ]);
    }

    /** One view per article per session, never a crawler's. */
    protected function countView(Request $request, Post $post): void
    {
        $seen = (array) session('blog_viewed', []);

        if (in_array($post->id, $seen, true) || app(\Torgash\Catalog\Support\Popularity::class)->isBot($request)) {
            return;
        }

        // A plain query: a view is not an edit, and must not move updated_at.
        \Illuminate\Support\Facades\DB::table('m_blog_posts')->where('id', $post->id)->increment('views_count');
        session(['blog_viewed' => array_slice([...$seen, $post->id], -100)]);
    }

    protected function redirectOr404(string $path)
    {
        if ($target = $this->redirects->resolve($path)) {
            return redirect($target, 301);
        }

        abort(404);
    }
}
