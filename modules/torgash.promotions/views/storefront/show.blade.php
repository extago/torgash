@extends('layouts.storefront')

@section('title', $promotion->pageTitle().' · '.current_storefront()?->name)

@section('content')
    @php
        $status = $promotion->status();
        $countdownUntil = $promotion->show_countdown && $status === 'running' ? $promotion->countdownEndsAt() : null;
    @endphp

    @php
        $showOffer = app(\Torgash\Promotions\Showcase::class)->headline($promotion);
        $showCyclic = $promotion->show_schedule && ! $promotion->scheduleWindows()->isEmpty();
    @endphp
    <x-breadcrumbs />

    {{-- The sale's own colour and its offer large beside the words — the
         same art its tile draws in the list (partials/promo-card), so the
         page is recognisably the card that was pressed. A banner, when the
         sale has one, stands in its place. --}}
    <div class="torgash-promo-hero bg-base-100 border border-base-300 rounded-box overflow-hidden mb-6 grid md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]"
         style="--promo-hue: {{ $promotion->hue() }}">
        <div class="torgash-promo-pic">
            @if ($promotion->bannerUrl())
                <img src="{{ $promotion->bannerUrl() }}" alt="{{ $promotion->t('name') }}">
                @if ($showOffer)
                    <span class="torgash-promo-chip">{{ $showOffer }}</span>
                @endif
            @else
                <span class="torgash-promo-art">
                    <span class="torgash-promo-offer">{{ $showOffer ?? '%' }}</span>
                    <x-icon :name="$showCyclic ? 'repeat' : 'badge-percent'" class="torgash-promo-icon" />
                </span>
            @endif
            @if ($showCyclic)
                <span class="torgash-promo-when">{{ $promotion->describeSchedule() }}</span>
            @endif
        </div>

        <div class="p-5 md:p-8 flex flex-col gap-3 justify-center">
            <h1 class="text-2xl md:text-3xl font-bold">{{ $promotion->t('name') }}</h1>

            {{-- The sale's own state, said plainly. A page that outlives its
                 sale (a link in last week's mail) must say so rather than
                 quietly showing yesterday's prices as if they still held. --}}
            @if ($status === 'ended')
                <div class="alert alert-warning py-2">{{ __('This sale has ended.') }}</div>
            @elseif ($status === 'scheduled')
                <div class="alert alert-info py-2">
                    {{ __('Starts :date', ['date' => $promotion->starts_at?->diffForHumans()]) }}
                </div>
            @elseif ($status === 'paused')
                <div class="alert alert-info py-2">
                    {{ $promotion->opensAt()
                        ? __('Opens :date', ['date' => $promotion->opensAt()->diffForHumans()])
                        : __('Not running right now.') }}
                </div>
            @endif

            @if ($countdownUntil)
                @include('torgash.promotions::partials.countdown', ['until' => $countdownUntil, 'label' => __('Ends in')])
            @endif

            @if ($promotion->t('description'))
                {{-- Admin-authored rich text, the same trust level and the same
                     wrapper class as a product description. --}}
                <div class="torgash-rich">{{ safe_html($promotion->t('description')) }}</div>
            @endif
            {{-- No machine-written "terms" block. A tier's discount without its
                 conditions reads as a promise the cart then refuses ("−50%"
                 when only one brand is included), and the conditions in words
                 are the admin's vocabulary, not a customer's. What the sale
                 covers is said by the merchant in the description above and
                 shown by the list of products below — which is the same answer
                 a promotion page usually gives. --}}
        </div>
    </div>

    @if ($listing)
        <h2 class="text-lg font-semibold mb-3">{{ __('Products in this sale') }}</h2>
        {{-- The catalogue's own listing, panel and all (Catalog\Listing): a
             sale over three categories is otherwise a grid of everything
             mixed together. Every name the partial reads is passed — an
             include inherits the caller's scope (docs/LESSONS.md). --}}
        @include('torgash.catalog::partials.listing', [
            'products' => $listing['products'],
            'filters' => $listing['filters'],
            'activeFilters' => $listing['activeFilters'],
            'sort' => $listing['sort'],
            'listing' => $listing['listing'],
            'currentUrl' => route('promotions.show', $promotion->slug),
            'category' => null,
            'categories' => [],
            'subcategories' => collect(),
            'filterPages' => ['filters' => collect(), 'places' => collect()],
            'filterPage' => null,
            'listingKeep' => [],
        ])
    @endif
@endsection
