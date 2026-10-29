@extends('layouts.storefront')

@section('content')
    <x-breadcrumbs />

    @php $intro = $filterPage?->t('description'); @endphp

    {{-- How many there are, beside the heading but outside it: the h1 is the
         page's name to a search engine, and a number is not part of it. --}}
    <div @class(['flex flex-wrap items-baseline gap-x-3', 'mb-2' => $intro, 'mb-4' => ! $intro])>
        <h1 class="text-2xl font-bold">{{ page_meta()->h1() }}</h1>
        @if (method_exists($products, 'total') && $products->total() > 0)
            <span class="text-sm opacity-60 tabular-nums">{{ trans_choice(':count product|:count products', $products->total(), ['count' => $products->total()]) }}</span>
        @endif
    </div>

    {{-- The page's own introduction, its placeholders ({min_price}) the
         page's: three lines and "Read in full" (app.css .torgash-intro), so a
         long text does not push the goods off the first screen — the whole of
         it is in the page all the same. --}}
    @if ($intro)
        <div class="torgash-intro mb-5 max-w-3xl"
             x-data="{
                long: false,
                open: false,
                measure() { const body = this.$refs.introBody; this.long = this.open || body.scrollHeight > body.clientHeight + 1 },
             }"
             x-init="measure(); new ResizeObserver(() => measure()).observe($refs.introBody)"
             :class="{ 'is-long': long, 'is-open': open }">
            <div x-ref="introBody" class="torgash-intro-body torgash-rich leading-relaxed text-base-content/80">{{ safe_html($intro, \App\Core\Seo\PageMeta::contextOf($filterPage)) }}</div>
            <button type="button" x-show="long" x-cloak x-on:click="open = ! open"
                    class="btn btn-link btn-sm btn-touch px-0 gap-1">
                <span x-text="open ? @js(__('Fold the text')) : @js(__('Read in full'))"></span>
                <span :class="open && 'rotate-180'" class="transition-transform"><x-icon name="chevron-down" size="sm" /></span>
            </button>
        </div>
    @endif

    {{-- The shop's own blocks (banners, a text, a row of products), placed
         by display rules — "listing category" answers which category is open,
         with its subtree. OUTSIDE the filter form, both of them: a block may
         hold a form of its own (a product's buy button), and a form inside a
         form is the trap that silently submits the wrong one. --}}
    @php $zoneContext = ['category' => $category, 'filter_page' => $filterPage]; @endphp
    @php $zoneTop = zone('catalog.category.top', $zoneContext); @endphp
    @if (trim($zoneTop) !== '')
        <div class="mb-4 flex flex-col gap-4">{!! $zoneTop !!}</div>
    @endif

    @php $currentUrl = $category ? route('catalog.category', $category->slug) : route('catalog.index'); @endphp
    @include('torgash.catalog::partials.listing')

    {{-- The category's own text, under the goods rather than above them: the
         visitor came for the products. First page only — the same paragraph
         on page 7 is a duplicate a crawler notices. A filter page has its own
         introduction above. --}}
    @php
        $categoryText = ! $filterPage && $category &&(! method_exists($products, 'currentPage') || $products->currentPage() === 1)
            ? trim((string) $category->t('description')) : '';
    @endphp
    @if (trim(strip_tags($categoryText)) !== '')
        <section class="mt-10 max-w-4xl">
            <div class="torgash-rich opacity-80 leading-relaxed">{{ safe_html($categoryText, \App\Core\Seo\PageMeta::contextOf($category)) }}</div>
        </section>
    @endif

    @php $zoneBottom = zone('catalog.category.bottom', $zoneContext); @endphp
    @if (trim($zoneBottom) !== '')
        <div class="mt-6 flex flex-col gap-4">{!! $zoneBottom !!}</div>
    @endif
@endsection
