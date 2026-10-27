{{-- The shop's mark for a footer or a column: the average, the stars and how
     many, linking to the reviews page. Nothing at all before the first
     published review — an empty "0.0" in the footer argues against the shop. --}}
@php($srSummary = app(\Torgash\ShopReviews\Stats::class)->summary())
@if ($srSummary['count'] > 0)
    <div class="flex flex-col gap-1.5">
        @if (filled($srHeading = trim((string) ($settings['heading'] ?? ''))))
            <div class="font-semibold">{{ $srHeading }}</div>
        @endif
        <a href="{{ route('shopreviews.index') }}" class="inline-flex flex-wrap items-center gap-x-2 gap-y-1 link link-hover w-fit">
            <b class="text-2xl leading-none">{{ number_format($srSummary['rating'], 1) }}</b>
            @include('torgash.shopreviews::storefront._stars', ['srRating' => $srSummary['rating'], 'srSize' => 'sm'])
            <span class="text-sm opacity-70">{{ trans_choice('{1}:count review|[2,*]:count reviews', $srSummary['count'], ['count' => $srSummary['count']]) }}</span>
        </a>
        @if ($srSummary['recommend'] !== null)
            <span class="text-sm opacity-70">{{ __(':share% recommend the shop', ['share' => $srSummary['recommend']]) }}</span>
        @endif
    </div>
@endif
