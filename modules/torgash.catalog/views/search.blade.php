@extends('layouts.storefront')

@section('content')
    <x-breadcrumbs />

    <h1 class="text-2xl font-bold mb-1">{{ __('Search results') }}</h1>
    @php $products = $listingPage['products']; @endphp
    <p class="opacity-60 text-sm mb-4">
        {{ __('for') }} &ldquo;{{ $q }}&rdquo;
    </p>

    {{-- Say so when the query had to be read differently to find anything —
         answering another question silently is how a customer stops trusting
         the box. --}}
    @if (($readHow ?? null) === 'layout' && ($readAs ?? null))
        <div class="alert alert-info alert-soft text-sm mb-4">
            <x-icon name="keyboard" />
            <span>{{ __('Showing results for «:q» — it looks like the keyboard was on another layout.', ['q' => $readAs]) }}</span>
        </div>
    @elseif (($readHow ?? null) === 'typo' && ($readAs ?? null))
        <div class="alert alert-info alert-soft text-sm mb-4">
            <x-icon name="spell-check" />
            <span>{{ __('Nothing was found for «:typed» — showing results for «:q».', ['typed' => $q, 'q' => $readAs]) }}</span>
        </div>
    @elseif (($readHow ?? null) === 'fallback' && $products->isNotEmpty())
        <div class="alert alert-info alert-soft text-sm mb-4">
            <x-icon name="spell-check" />
            <span>{{ __('Nothing matched exactly — these are the closest answers.') }}</span>
        </div>
    @endif

    @if ($didYouMean ?? null)
        <p class="text-sm mb-4">
            {{ __('Did you mean') }}
            <a href="{{ route('catalog.search', ['q' => $didYouMean]) }}" class="link link-primary font-semibold">{{ $didYouMean }}</a>?
        </p>
    @endif

    @foreach ($sections ?? [] as $section)
        <section class="mb-6">
            <h2 class="text-sm uppercase tracking-wide opacity-60 mb-2">{{ $section['title'] }}</h2>
            <ul class="flex flex-col gap-1">
                @foreach ($section['items'] as $item)
                    <li>
                        <a href="{{ $item['url'] }}" class="link link-hover">{{ $item['label'] }}</a>
                        @if ($item['hint'] ?? null)<span class="text-xs opacity-50 ml-2">{{ $item['hint'] }}</span>@endif
                    </li>
                @endforeach
            </ul>
        </section>
    @endforeach

    @if ($products->total() === 0 && ($sections ?? []) === [] && $listingPage['activeFilters'] === [])
        <div class="alert">{{ __('Nothing found.') }} <a href="{{ route('catalog.index') }}" class="link link-primary">{{ __('Browse all products') }}</a></div>
    @elseif ($products->total() > 0 || $listingPage['activeFilters'] !== [])
        @if (($sections ?? []) !== [])
            <h2 class="text-sm uppercase tracking-wide opacity-60 mb-2">{{ __('Products') }}</h2>
        @endif
        {{-- The catalogue's own listing over the answer (Catalog\Listing):
             the panel offers what narrows THESE products, a Category block
             among them, and "Relevance" leads the sorts. `q` (and what the
             search was asked for — `scope`) ride every submit. Every name the
             partial reads is passed: an include inherits the caller's scope. --}}
        @include('torgash.catalog::partials.listing', [
            'products' => $products,
            'filters' => $listingPage['filters'],
            'activeFilters' => $listingPage['activeFilters'],
            'sort' => $listingPage['sort'],
            'listing' => $listingPage['listing'],
            'currentUrl' => route('catalog.search', array_filter(['q' => $q, 'scope' => request()->query('scope')])),
            'listingAction' => route('catalog.search'),
            'listingKeep' => array_filter(['q' => $q, 'scope' => (string) request()->query('scope', '')]),
            'category' => null,
            'categories' => [],
            'subcategories' => collect(),
            'filterPages' => ['filters' => collect(), 'places' => collect()],
            'filterPage' => null,
        ])
    @endif
@endsection
