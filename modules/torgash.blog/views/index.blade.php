@extends('layouts.storefront')

{{-- The blog's feed (PLAN §47): the rubrics are the way in, instead of tags
     and a monthly archive — a column on the left, like the catalogue's
     categories, because a row of plates across the top stops working at
     twenty of them. On a phone the SAME list is a strip that scrolls
     sideways above the articles (one list, two layouts — never two copies).
     Blocks the shop put in `blog.sidebar` go under it. --}}
@section('content')
    @php
        $blogSidebar = zone('blog.sidebar', ['rubric' => $rubric]);
        $hasRubrics = $rubrics->isNotEmpty();
    @endphp

    <x-breadcrumbs />

    <header class="mb-4 flex flex-col gap-2">
        <h1 class="text-3xl font-bold">{{ page_meta()->h1() }}</h1>
        @if ($rubric && ($rubricText = trim(strip_tags((string) $rubric->t('description')))))
            <p class="opacity-75 max-w-3xl">{{ $rubricText }}</p>
        @endif
    </header>

    {{-- A grid, so the order differs without a second copy: on a computer the
         rubrics and the shop's blocks share the left column; on a phone it is
         rubrics, articles, then the blocks. --}}
    @php $blogHasColumn = $hasRubrics || trim($blogSidebar) !== ''; @endphp
    <div @class(['grid gap-4 lg:gap-x-6 items-start', 'lg:grid-cols-[16rem_minmax(0,1fr)] lg:grid-rows-[auto_1fr]' => $blogHasColumn])>
                @if ($hasRubrics)
                    <nav class="min-w-0 lg:col-start-1 lg:row-start-1 lg:bg-base-100 lg:border lg:border-base-300 lg:rounded-box lg:p-3" aria-label="{{ __('Rubrics') }}">
                        <div class="hidden lg:block text-lg font-bold px-2 pt-1 pb-2">{{ __('Rubrics') }}</div>
                        <ul class="flex lg:flex-col gap-2 lg:gap-0 overflow-x-auto lg:overflow-visible -mx-3 px-3 sm:-mx-4 sm:px-4 lg:mx-0 lg:px-0 pb-1 lg:pb-0 [scrollbar-width:none] text-sm">
                            @php
                                $blogNav = collect([['rubric' => null, 'url' => route('blog.index'), 'name' => __('All articles'), 'count' => $allCount, 'colour' => null, 'icon' => 'newspaper']])
                                    ->concat($rubrics->map(fn ($r) => ['rubric' => $r, 'url' => $r->url(), 'name' => $r->t('name'), 'count' => $r->listed_count, 'colour' => $r->colour(), 'icon' => $r->icon]));
                            @endphp
                            @foreach ($blogNav as $blogNavRow)
                                @php $blogNavHere = $blogNavRow['rubric'] ? $rubric?->id === $blogNavRow['rubric']->id : ! $rubric; @endphp
                                <li class="shrink-0">
                                    <a href="{{ $blogNavRow['url'] }}" @if ($blogNavHere) aria-current="page" @endif
                                       @class([
                                           'relative flex items-center gap-2 min-h-9 whitespace-nowrap rounded-full lg:rounded-field px-3 lg:px-2 border lg:border-0 hover:bg-base-200',
                                           'bg-base-100 border-base-300' => ! $blogNavHere,
                                           'font-bold bg-base-100 border-primary lg:bg-transparent' => $blogNavHere,
                                       ])>
                                        @if ($blogNavHere)
                                            <span class="hidden lg:block absolute -left-3.25 inset-y-1 w-[3px] rounded-r-full bg-primary" aria-hidden="true"></span>
                                        @endif
                                        @if ($blogNavRow['icon'])
                                            <span class="shrink-0" @if ($blogNavRow['colour']) style="color: {{ $blogNavRow['colour'] }}" @endif><x-icon :name="$blogNavRow['icon']" size="sm" /></span>
                                        @endif
                                        <span class="grow lg:whitespace-normal leading-snug">{{ $blogNavRow['name'] }}</span>
                                        <span class="shrink-0 min-w-6 px-1.5 rounded-full text-xs text-center tabular-nums leading-5 bg-base-200 text-base-content/60">{{ $blogNavRow['count'] }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </nav>
                @endif

        <div @class(['min-w-0', 'lg:col-start-2 lg:row-start-1 lg:row-span-2' => $blogHasColumn])>
            @if ($posts->isEmpty())
                <div class="bg-base-100 border border-base-300 rounded-box p-10 text-center opacity-60">{{ __('No articles yet.') }}</div>
            @else
                <div class="grid gap-5 grid-cols-1 md:grid-cols-2 xl:grid-cols-3">
                    @include('torgash.blog::partials.cards', ['posts' => $posts, 'more' => false])
                </div>
            @endif
        </div>

        @if (trim($blogSidebar) !== '')
            <aside class="min-w-0 flex flex-col gap-4 lg:col-start-1 lg:row-start-2">{!! $blogSidebar !!}</aside>
        @endif
    </div>
@endsection
