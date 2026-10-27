@extends('layouts.storefront')

@section('content')
    <x-breadcrumbs />

    <div class="flex items-center gap-4 mb-6">
        @if ($brand->logoUrl())
            <div class="h-16 w-16 shrink-0 bg-base-100 rounded-box border border-base-300 flex items-center justify-center p-2">
                <img src="{{ $brand->logoUrl() }}" alt="{{ $brand->display() }}" class="max-h-full max-w-full object-contain">
            </div>
        @endif
        <div>
            <h1 class="text-2xl font-bold">{{ page_meta()->h1() }}</h1>
            @if ($brand->t('description'))
                {{-- Admin-authored markup from the rich text editor. --}}
                <div class="torgash-rich opacity-70 text-sm mt-1 max-w-2xl">{{ safe_html($brand->t('description')) }}</div>
            @endif
        </div>
    </div>

    {{-- The catalogue's own listing over the brand's products, as the
         search page draws it: the panel, the chips, the sorts. Every name the
         partial reads is passed — an include inherits the caller's scope. --}}
    @include('torgash.catalog::partials.listing', [
        'products' => $listingPage['products'],
        'filters' => $listingPage['filters'],
        'activeFilters' => $listingPage['activeFilters'],
        'sort' => $listingPage['sort'],
        'listing' => $listingPage['listing'],
        'currentUrl' => route('catalog.brands.show', $brand->slug),
        'listingAction' => route('catalog.brands.show', $brand->slug),
        'listingKeep' => [],
        'category' => null,
        'categories' => [],
        'subcategories' => collect(),
        'filterPages' => ['filters' => collect(), 'places' => collect()],
        'filterPage' => null,
    ])
@endsection
