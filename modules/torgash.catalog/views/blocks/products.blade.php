{{-- A product block — see Torgash\Catalog\Selections\ProductBlock. Prints
     nothing at all when nothing matches: an empty block is not a tab either
     (the zone drops it from a row of tabs), and a heading over "No products
     yet" on a home page is a shop apologising for itself. --}}
@php
    $blockSelection = app(\Torgash\Catalog\Selections\Selection::class);
    // The zone's context: on the product page, the product "similar" is about.
    $blockProducts = $blockSelection->products($settings, $zoneContext ?? []);
@endphp
@if ($blockProducts->isNotEmpty())
    @php
        $blockHeading = $blockSelection->heading($settings);
        $blockUrl = ! empty($settings['link_all']) ? $blockSelection->url($settings) : null;
    @endphp
    <div @class(['torgash-block torgash-block-products', 'mb-10' => ! $inTabs])>
        @php $blockCarousel = ($settings['layout'] ?? 'grid') === 'carousel'; @endphp
        {{-- In a row of tabs the tab IS the heading. A carousel draws the
             heading itself, so its small arrows can stand in the same row. --}}
        @if (! $inTabs && ! $blockCarousel && ($blockHeading !== '' || $blockUrl))
            <div class="flex items-baseline justify-between gap-4 mb-4">
                <h2 class="text-lg font-semibold">{{ $blockHeading }}</h2>
                @if ($blockUrl)
                    <a href="{{ $blockUrl }}" class="link link-hover text-sm whitespace-nowrap">{{ __('View all') }} &rarr;</a>
                @endif
            </div>
        @endif

        @if ($blockCarousel)
            @include('torgash.catalog::partials.product-carousel', [
                'carouselProducts' => $blockProducts,
                'carouselCols' => (int) ($settings['per_row'] ?? 4),
                'carouselArrows' => $settings['arrows'] ?? 'side',
                'carouselArrowsPhone' => $settings['arrows_phone'] ?? 'head',
                'carouselHeading' => $inTabs ? '' : $blockHeading,
                'carouselAllUrl' => $inTabs ? null : $blockUrl,
            ])
        @else
            @include('torgash.catalog::partials.product-grid', ['products' => $blockProducts, 'gridReveal' => (int) ($settings['reveal'] ?? 0), 'gridCols' => (int) ($settings['per_row'] ?? 4)])
        @endif

        @if ($inTabs && $blockUrl)
            <div class="text-end mt-3">
                <a href="{{ $blockUrl }}" class="link link-hover text-sm">{{ __('View all') }} &rarr;</a>
            </div>
        @endif
    </div>
@endif
