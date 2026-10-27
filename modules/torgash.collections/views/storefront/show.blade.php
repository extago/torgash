@extends('layouts.storefront')

@section('title', ($collection->t('meta_title') ?: $collection->t('name')).' · '.current_storefront()?->name)

@section('content')
    <x-breadcrumbs />

    {{-- The cover and the words, then the catalogue's own listing over the
         collection's products (Catalog\Listing) — its panel offers only what
         narrows THIS set. --}}
    <div class="bg-base-100 border border-base-300 rounded-box overflow-hidden mb-6 grid @if ($collection->coverUrl()) md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)] @endif">
        @if ($collectionCover = $collection->coverUrl())
            <img src="{{ $collectionCover }}" alt="{{ $collection->t('name') }}" class="w-full h-full object-cover max-h-80 md:max-h-none">
        @endif
        <div class="p-5 md:p-8 flex flex-col gap-3 justify-center">
            <h1 class="text-2xl md:text-3xl font-bold">{{ $collection->t('name') }}</h1>
            @if ($collection->t('description'))
                <div class="torgash-rich">{{ safe_html($collection->t('description')) }}</div>
            @endif
        </div>
    </div>

    @include('torgash.collections::storefront.looks', ['looksShown' => $collection->looks, 'looksClass' => 'mb-6'])

    {{-- Every name the partial reads is passed — an include inherits the
         caller's scope (docs/LESSONS.md). --}}
    @include('torgash.catalog::partials.listing', [
        'products' => $listing['products'],
        'filters' => $listing['filters'],
        'activeFilters' => $listing['activeFilters'],
        'sort' => $listing['sort'],
        'listing' => $listing['listing'],
        'currentUrl' => route('collections.show', $collection->slug),
        'category' => null,
        'categories' => [],
        'subcategories' => collect(),
        'filterPages' => ['filters' => collect(), 'places' => collect()],
        'filterPage' => null,
        'listingKeep' => [],
    ])
@endsection
