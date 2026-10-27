{{-- One row of product cards that scrolls sideways. The scrolling is the
     browser's own (CSS scroll-snap, daisyUI's carousel), so it works by touch
     and trackpad with no script at all; the arrows are the only thing Alpine
     adds, and they are hidden until it is there to make them work.

     Two kinds of arrow, picked separately for a computer/tablet and for a
     phone (the block's settings): round ones at the SIDES, over the cards —
     on a phone two cards fill the width and those sit on the SKU and the
     picture — or small square ones in the row ABOVE, on the right, beside
     the heading when the caller hands it over (`carouselHeading`, and its
     "View all" link `carouselAllUrl`). `none` leaves the swipe alone. An
     arrow goes quiet at its end of the row, and none is drawn when the
     whole row is already in view. --}}
@php
    $carouselCards = \Torgash\Catalog\Support\ProductCards::prime($carouselProducts);
    // How many are in view at once on a computer — the block's "in a row".
    $carouselWidth = [3 => 'xl:w-1/3', 4 => 'xl:w-1/4', 5 => 'xl:w-1/5', 6 => 'xl:w-1/6'][(int) ($carouselCols ?? 4)] ?? 'xl:w-1/4';
    $carouselKinds = ['side', 'head', 'none'];
    $carouselArrowsWide = in_array($carouselArrows ?? 'side', $carouselKinds, true) ? ($carouselArrows ?? 'side') : 'side';
    $carouselArrowsPhone = in_array($carouselArrowsPhone ?? 'head', $carouselKinds, true) ? ($carouselArrowsPhone ?? 'head') : 'head';
    $carouselHeading = (string) ($carouselHeading ?? '');
    $carouselAllUrl = $carouselAllUrl ?? null;
    $carouselMoves = $carouselCards['products']->count() > 2;
@endphp
<div class="torgash-carousel" data-arrows="{{ $carouselArrowsWide }}" data-arrows-phone="{{ $carouselArrowsPhone }}"
     x-data="{ atStart: true, atEnd: false,
               look() { const t = $refs.track; this.atStart = t.scrollLeft < 4; this.atEnd = t.scrollLeft + t.clientWidth >= t.scrollWidth - 4; },
               go(d) { $refs.track.scrollBy({ left: d * $refs.track.clientWidth, behavior: 'smooth' }); } }"
     x-init="look()" x-on:resize.window.debounce.100ms="look()">
    <div class="torgash-carousel-top flex items-center justify-between gap-4 mb-3" @if ($carouselHeading !== '' || $carouselAllUrl) data-has-heading @endif>
        <div class="flex items-baseline gap-4 min-w-0">
            @if ($carouselHeading !== '')
                <h2 class="text-lg font-semibold">{{ $carouselHeading }}</h2>
            @endif
        </div>
        <div class="flex items-center gap-3 shrink-0">
            @if ($carouselAllUrl)
                <a href="{{ $carouselAllUrl }}" class="link link-hover text-sm whitespace-nowrap">{{ __('View all') }} &rarr;</a>
            @endif
            @if ($carouselMoves)
                <div class="torgash-carousel-head" x-cloak x-show="! (atStart && atEnd)">
                    <button type="button" class="btn btn-sm btn-square" aria-label="{{ __('Previous') }}" x-bind:disabled="atStart" x-on:click="go(-1)"><x-icon name="chevron-left" size="sm" /></button>
                    <button type="button" class="btn btn-sm btn-square" aria-label="{{ __('Next') }}" x-bind:disabled="atEnd" x-on:click="go(1)"><x-icon name="chevron-right" size="sm" /></button>
                </div>
            @endif
        </div>
    </div>
    <div class="relative">
        <div class="bg-base-100 border border-base-300 rounded-box overflow-hidden">
            <div class="carousel w-full -mr-px -mb-px" x-ref="track" x-on:scroll.debounce.60ms="look()">
                @foreach ($carouselCards['products'] as $product)
                    <div class="carousel-item w-1/2 md:w-1/3 {{ $carouselWidth }}">
                        @include('torgash.catalog::partials.product-card', [
                            'product' => $product,
                            'cardLabels' => $carouselCards['labels'][$product->id] ?? [],
                            'cardShortDescription' => $carouselCards['short'][$product->id] ?? '',
                            'cardAttributes' => '',
                            'cardClass' => 'w-full border-b border-r border-base-300',
                            // The track clips anything past its own height.
                            'cardHoverPanel' => false,
                        ])
                    </div>
                @endforeach
            </div>
        </div>
        @if ($carouselMoves)
            <button type="button" x-cloak x-show="! atStart" aria-label="{{ __('Previous') }}"
                    class="torgash-carousel-side btn btn-circle btn-sm absolute left-2 top-1/3 z-[25] shadow"
                    x-on:click="go(-1)">
                <x-icon name="chevron-left" size="sm" />
            </button>
            <button type="button" x-cloak x-show="! atEnd" aria-label="{{ __('Next') }}"
                    class="torgash-carousel-side btn btn-circle btn-sm absolute right-2 top-1/3 z-[25] shadow"
                    x-on:click="go(1)">
                <x-icon name="chevron-right" size="sm" />
            </button>
        @endif
    </div>
</div>
