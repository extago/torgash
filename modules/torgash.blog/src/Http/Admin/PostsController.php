<?php

namespace Torgash\Blog\Http\Admin;

use App\Core\Seo\MetaFields;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Torgash\Blog\Models\Post;
use Torgash\Blog\Models\Rubric;
use Torgash\Catalog\Models\Product;

/**
 * The blog's articles in the admin (PLAN §47): the list — searched, by
 * status and by rubric — and the form. Texts are written one language at a
 * time (the content language at the top), as every text in this admin is.
 */
class PostsController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');
        $rubric = $request->integer('rubric') ?: null;

        $posts = Post::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w
                ->whereRaw('exists (select 1 from jsonb_each_text(title) t(k, v) where t.v ilike ?)', ['%'.addcslashes($q, '%_\\').'%'])
                ->orWhere('slug', 'ilike', '%'.addcslashes($q, '%_\\').'%')))
            ->when(in_array($status, Post::STATUSES, true), fn ($query) => $query->where('status', $status))
            ->when($status === 'scheduled', fn ($query) => $query->where('status', '!=', Post::DRAFT)->where('published_at', '>', now()))
            ->when($rubric, fn ($query) => $query->whereHas('rubrics', fn ($r) => $r->whereKey($rubric)))
            ->with(['rubrics', 'media'])
            ->orderByRaw('published_at desc nulls first')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        return view('torgash.blog::admin.index', [
            'posts' => $posts,
            'filters' => ['q' => $q, 'status' => $status, 'rubric' => $rubric],
            'rubrics' => Rubric::orderBy('sort')->get(),
            'counts' => Post::query()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ]);
    }

    public function create()
    {
        return $this->form(new Post(['status' => Post::DRAFT, 'comments_enabled' => true]));
    }

    public function store(Request $request)
    {
        $post = new Post;
        $this->save($request, $post);

        return $this->afterSave($request, $post, __('Article created.'));
    }

    public function edit(Post $post)
    {
        return $this->form($post->load('rubrics', 'products'));
    }

    public function update(Request $request, Post $post)
    {
        $this->save($request, $post);

        return $this->afterSave($request, $post, __('Article saved.'));
    }

    public function destroy(Post $post)
    {
        $post->delete();

        return redirect()->route('admin.blog.index')->with('status', __('Article deleted.'));
    }

    protected function form(Post $post)
    {
        return view('torgash.blog::admin.form', [
            'post' => $post,
            'rubrics' => Rubric::orderBy('sort')->orderBy('id')->get(),
            'authors' => User::query()->staff()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    protected function afterSave(Request $request, Post $post, string $message)
    {
        return ($request->input('after_save') === 'close'
            ? redirect()->route('admin.blog.index')
            : redirect()->route('admin.blog.edit', $post))->with('status', $message);
    }

    protected function save(Request $request, Post $post): void
    {
        $locale = admin_content_locale();

        $validated = $request->validate([
            'title' => [content_locale_is_base() && ! $post->exists ? 'required' : 'nullable', 'string', 'max:300'],
            'slug' => ['nullable', 'string', 'max:190'],
            'lead' => ['nullable', 'string', 'max:2000'],
            'body' => ['nullable', 'string'],
            'status' => ['required', Rule::in(Post::STATUSES)],
            'published_at' => ['nullable', 'date'],
            'author_id' => ['nullable', 'integer'],
            'rubrics' => ['array'],
            'rubrics.*' => ['integer', 'exists:m_blog_rubrics,id'],
            'products' => ['nullable', 'string', 'max:5000'],
        ] + MetaFields::rules());

        $title = put_translation($post->title, $validated['title'] ?? '', $locale);
        $author = ! empty($validated['author_id'])
            ? User::query()->staff()->find($validated['author_id'])
            : ($post->exists ? null : $request->user());

        DB::transaction(function () use ($post, $request, $validated, $title, $locale, $author) {
            $post->fill([
                'title' => $title,
                'slug' => $this->slug($validated['slug'] ?? '', $title, $post),
                'lead' => put_translation($post->lead, $validated['lead'] ?? '', $locale),
                'body' => put_translation($post->body, $validated['body'] ?? '', $locale),
                'status' => $validated['status'],
                'published_at' => filled($validated['published_at'] ?? null) ? $validated['published_at'] : $post->published_at,
                'comments_enabled' => $request->boolean('comments_enabled'),
                ...($author ? ['author_id' => $author->id, 'author_name' => $author->name] : []),
                ...(array_key_exists('author_id', $validated) && empty($validated['author_id']) && $post->exists ? ['author_id' => null, 'author_name' => null] : []),
            ] + MetaFields::attributes($request, $post));
            $post->save();

            $post->rubrics()->sync(collect($validated['rubrics'] ?? [])->values()->mapWithKeys(fn ($id, $i) => [(int) $id => ['position' => $i]])->all());

            // Products named by SKU, in the order typed; an unknown SKU is
            // simply not linked (the form says which).
            $skus = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($validated['products'] ?? '')) ?: []))));
            $found = Product::whereIn('sku', $skus)->pluck('id', 'sku');
            $post->products()->sync(collect($skus)->filter(fn ($sku) => $found->has($sku))->values()
                ->mapWithKeys(fn ($sku, $i) => [$found[$sku] => ['position' => $i]])->all());

            $missing = array_values(array_diff($skus, $found->keys()->all()));
            if ($missing) {
                session()->flash('warning', __('No product with these SKUs: :skus', ['skus' => implode(', ', $missing)]));
            }

            // What another module drew on the form (blog.post.form_sections) is read back here.
            hook_action('blog.post.saved', ['record' => $post, 'request' => $request]);
        });
    }

    /** Made from the title when left empty; unique by a suffix rather than a refusal. */
    protected function slug(string $typed, array $title, Post $post): string
    {
        $base = Str::slug($typed !== '' ? $typed : (string) ($title[config('app.locale')] ?? reset($title) ?: 'article')) ?: 'article';
        $slug = $base;

        for ($n = 2; Post::where('slug', $slug)->whereKeyNot($post->id ?? 0)->exists(); $n++) {
            $slug = $base.'-'.$n;
        }

        return $slug;
    }
}
