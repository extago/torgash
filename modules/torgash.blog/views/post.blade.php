@extends('layouts.storefront')

{{-- An article (PLAN §47): plates, title, when/how long/who, cover, lead and
     text; its CONTENTS beside it — built from the text's own headings and
     following the reader down the page; the products it is about; a heart
     and "share"; "read also"; and whatever the shop put under an article
     (`blog.post.bottom` — comments, when that module is here). --}}
@section('content')
    @php
        $postBottom = zone('blog.post.bottom', ['post' => $post, 'comments' => $post->comments_enabled]);
        $showContents = count($contents) >= 2;
    @endphp

    <x-breadcrumbs />

    <div class="flex flex-col lg:flex-row gap-6 items-start">
        @if ($showContents)
            {{-- Follows the reader: the section on screen is lit (an
                 IntersectionObserver in Alpine — the list works as plain
                 anchors without it). --}}
            <aside class="w-full lg:w-64 shrink-0 lg:sticky lg:top-[calc(var(--torgash-header-h,4rem)+1rem)] order-2 lg:order-1"
                   x-data="{ current: null }"
                   x-init="const seen = new Map();
                           const io = new IntersectionObserver((entries) => {
                               entries.forEach(e => seen.set(e.target.id, e.isIntersecting));
                               const first = [...document.querySelectorAll('[data-blog-heading]')].find(h => seen.get(h.id));
                               if (first) current = first.id;
                           }, { rootMargin: '-15% 0px -70% 0px' });
                           document.querySelectorAll('[data-blog-heading]').forEach(h => io.observe(h));">
                <nav class="bg-base-100 border border-base-300 rounded-box p-4" aria-label="{{ __('In this article') }}">
                    <div class="font-semibold mb-2">{{ __('In this article') }}</div>
                    <ul class="flex flex-col gap-1 text-sm">
                        @foreach ($contents as $entry)
                            <li @class(['ms-3' => $entry['level'] === 3])>
                                <a href="#{{ $entry['id'] }}"
                                   class="block border-s-2 ps-2 py-0.5 hover:text-primary"
                                   :class="current === @js($entry['id']) ? 'border-primary text-primary font-medium' : 'border-transparent opacity-80'">
                                    {{ $entry['text'] }}
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </nav>
                <a href="{{ route('blog.index') }}" class="btn btn-ghost btn-sm mt-3 gap-1.5"><x-icon name="arrow-left" size="sm" /> {{ __('Back to the blog') }}</a>
            </aside>
        @endif

        <div class="grow min-w-0 w-full flex flex-col gap-5 order-1 lg:order-2">
            <article class="bg-base-100 border border-base-300/70 rounded-box overflow-hidden">
                <div class="p-6 md:p-10 flex flex-col gap-4">
                    @if ($post->rubrics->isNotEmpty())
                        <div class="flex flex-wrap gap-1.5">
                            @include('torgash.blog::partials.plates', ['plateRubrics' => $post->rubrics])
                        </div>
                    @endif

                    <h1 class="text-3xl md:text-4xl font-bold leading-tight">{{ page_meta()->h1() }}</h1>

                    <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm opacity-60">
                        <time datetime="{{ $post->published_at?->toIso8601String() }}">{{ $post->published_at?->translatedFormat('j F Y') }}</time>
                        <span class="inline-flex items-center gap-1"><x-icon name="clock" size="sm" /> {{ trans_choice(':count minute to read|:count minutes to read', $post->reading_minutes, ['count' => $post->reading_minutes]) }}</span>
                        @if ($post->author_name)
                            <span class="inline-flex items-center gap-1"><x-icon name="pen-line" size="sm" /> {{ $post->author_name }}</span>
                        @endif
                        <span class="inline-flex items-center gap-1"><x-icon name="eye" size="sm" /> {{ $post->views_count }}</span>
                    </div>
                </div>

                @if ($cover = $post->coverUrl(1600))
                    <img src="{{ $cover }}" alt="{{ $post->t('title') }}" class="w-full max-h-[32rem] object-cover">
                @endif

                <div @class(['px-6 md:px-10 pb-6 md:pb-10 flex flex-col gap-6', 'pt-6 md:pt-10' => (bool) $cover, 'pt-0' => ! $cover])>
                    @if ($lead = trim(strip_tags((string) $post->t('lead'))))
                        <p class="text-lg md:text-xl leading-relaxed font-medium">{{ $lead }}</p>
                    @endif

                    {{-- The shop's own HTML, headings with their anchors and products
                         in their places (Blog\Article) — the trust level of a
                         product description. --}}
                    <div class="torgash-rich [&_h2]:scroll-mt-28 [&_h3]:scroll-mt-28">{!! $html !!}</div>

                    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 pt-5">
                        {{-- The visitor's own heart on a page kept ready for guests (PLAN §61). --}}
                        @personal('torgash.blog::partials.like-part', ['post' => $post], true)
                        @include('torgash.blog::partials.share', ['post' => $post])
                    </div>
                </div>
            </article>

            @if ($products->isNotEmpty())
                <section class="flex flex-col gap-3">
                    <h2 class="text-xl font-bold">{{ __('Products in this article') }}</h2>
                    @include('torgash.catalog::partials.product-carousel', ['carouselProducts' => $products, 'carouselCols' => 4])
                </section>
            @endif

            @if ($readAlso->isNotEmpty())
                <section class="flex flex-col gap-3">
                    <h2 class="text-xl font-bold">{{ __('Read also') }}</h2>
                    <div class="grid gap-5 grid-cols-1 md:grid-cols-3">
                        @foreach ($readAlso as $alsoPost)
                            @include('torgash.blog::partials.card', ['cardPost' => $alsoPost, 'cardWide' => false])
                        @endforeach
                    </div>
                </section>
            @endif

            @if (trim($postBottom) !== '')
                <section class="flex flex-col gap-3">{!! $postBottom !!}</section>
            @endif
        </div>
    </div>
@endsection
