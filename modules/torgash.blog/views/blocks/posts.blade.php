{{--
    "Latest articles" — see BlogServiceProvider. The newest listed articles
    (of one rubric, or all): `featured` puts the newest large on the left and
    the rest small beside it, `row` lays them out as equal cards. An article
    without a cover takes its first rubric's colour and icon, as in the feed.
    Prints nothing while the blog has nothing listed.
--}}
@php
    $blogLimit = max(1, min(12, (int) ($settings['limit'] ?? 5)));
    $blogRubric = (int) ($settings['rubric'] ?? 0);
    $blogPosts = \Torgash\Blog\Models\Post::query()->listed()
        ->when($blogRubric > 0, fn ($query) => $query->whereHas('rubrics', fn ($q) => $q->whereKey($blogRubric)))
        ->with(['rubrics' => fn ($q) => $q->where('enabled', true), 'media'])
        ->orderByDesc('published_at')->orderByDesc('id')
        ->limit($blogLimit)->get();
    $blogFeatured = ($settings['layout'] ?? 'featured') !== 'row' && $blogPosts->count() > 1;
    $blogHeading = trim((string) ($settings['heading'] ?? ''));
@endphp
@if ($blogPosts->isNotEmpty())
    <section @class(['torgash-block torgash-block-blog-posts', 'mb-10' => ! $inTabs])>
        @if (! $inTabs)
            <div class="flex items-baseline justify-between gap-4 mb-4">
                <h2 class="text-lg font-semibold">{{ $blogHeading }}</h2>
                <a href="{{ route('blog.index') }}" class="link link-hover text-sm whitespace-nowrap">{{ __('All articles') }} &rarr;</a>
            </div>
        @endif

        <div @class([
                'grid gap-4 lg:gap-5',
                'lg:grid-cols-[minmax(0,1.1fr)_minmax(0,1fr)]' => $blogFeatured,
                'grid-cols-2 lg:grid-cols-4' => ! $blogFeatured,
             ])>
            @foreach ($blogFeatured ? [$blogPosts->take(1), $blogPosts->slice(1)] : [$blogPosts] as $blogGroup)
                <div @class(['grid gap-4 lg:gap-5', 'grid-cols-2' => $blogFeatured && ! $loop->first, 'contents' => ! $blogFeatured])>
                    @foreach ($blogGroup as $blogPost)
                        @php
                            $blogLarge = $blogFeatured && $loop->parent->first;
                            $blogCover = $blogPost->coverUrl($blogLarge ? 1200 : 480);
                            $blogTint = $blogPost->rubrics->first()?->colour() ?? '#64748b';
                        @endphp
                        <article class="torgash-blog-tile group">
                            <a href="{{ $blogPost->url() }}" @class(['torgash-blog-cover', 'is-large' => $blogLarge])
                               @unless ($blogCover) style="background: linear-gradient(135deg, {{ $blogTint }}, {{ $blogTint }}99)" @endunless>
                                @if ($blogCover)
                                    <img src="{{ $blogCover }}" alt="{{ $blogPost->t('title') }}" loading="lazy">
                                @else
                                    <x-icon :name="$blogPost->rubrics->first()?->icon ?: 'newspaper'" class="w-12 h-12 text-white opacity-80" />
                                @endif
                                <span class="torgash-blog-date">{{ $blogPost->published_at?->translatedFormat('j F') }}</span>
                            </a>
                            <a href="{{ $blogPost->url() }}" @class([
                                    'font-semibold leading-snug hover:text-primary',
                                    'text-xl line-clamp-2' => $blogLarge,
                                    'text-sm line-clamp-3' => ! $blogLarge,
                               ])>{{ $blogPost->t('title') }}</a>
                            @if ($blogLarge && ($blogLead = trim(strip_tags((string) $blogPost->t('lead')))))
                                <p class="text-sm opacity-70 line-clamp-2">{{ $blogLead }}</p>
                            @endif
                        </article>
                    @endforeach
                </div>
            @endforeach
        </div>
    </section>
@endif
