{{-- The reviews of one product, in the order asked for, narrowed by the
     filters asked for, and the controls that change both.

     The controls are LINKS, not a form: with no JavaScript each one goes to
     the product page with the state in its address (Listing::state(), every
     parameter `review_*`) and the section reads the same parameters, so the
     same markup works both ways. HTMX only makes it quick — it replaces this
     element with itself, which carries the wiring back (the lesson the options
     form's docblock states at length). "Show more" is the same link with a
     larger `review_shown`.

     The filters are drawn from `filters_from` reviews on (0 never), and always
     while one is in force — a reader must be able to take it off. --}}
@php
    $listState = $state ?? \Torgash\Reviews\Listing::state(['reviews' => $order]);
    $listing = app(\Torgash\Reviews\Listing::class);
    $orders = \Torgash\Reviews\Listing::orders();
    // Counted, not read off the aggregate row: the controls and "shown N of
    // M" must agree with the rows below even if that row is a moment stale.
    $listAll = $listing->count((int) $product->id);
    $listFiltered = \Torgash\Reviews\Listing::filtered($listState);
    $listFound = $listFiltered ? $listing->count((int) $product->id, $listState) : $listAll;
    $listFiltersFrom = (int) setting('torgash.reviews', 'filters_from', 5);
    $listShowFilters = $listFiltered || ($listFiltersFrom > 0 && $listAll >= $listFiltersFrom);
    // Where variations share their reviews: what each member is called, for
    // the line over a review written about another one (Group::labels()).
    $reviewVariants = $reviews->contains(fn ($r) => (int) $r->product_id !== (int) $product->id)
        ? app(\Torgash\Reviews\Group::class)->labels($product)
        : [];

    // One link per state: the page address for no JS, the fragment for HTMX.
    // A change of filter or order starts again from the first page.
    $listLink = function (array $changes) use ($listState, $product) {
        $params = \Torgash\Reviews\Listing::params(array_merge($listState, ['shown' => \Torgash\Reviews\Listing::perPage()], $changes));

        return [
            'href' => route('catalog.product', $product->slug).($params ? '?'.http_build_query($params) : '').'#reviews',
            'hx' => route('reviews.list', ['product' => $product->slug] + $params),
        ];
    };
    $chip = 'inline-flex items-center gap-1 px-3 py-1.5 rounded-full border text-sm whitespace-nowrap transition-colors';
@endphp
<div id="reviews-list" class="flex flex-col torgash-dim-when-busy">
    @if ($listAll > 0)
        <div class="flex flex-col gap-3 pb-4 border-b border-base-300">
            {{-- The order: a segmented control, the one in force PRESSED. --}}
            <div class="flex flex-wrap items-center justify-between gap-3">
                <span class="text-sm opacity-60">{{ __('Sort by') }}</span>
                <nav class="flex flex-wrap gap-1 p-1 rounded-field bg-base-200" aria-label="{{ __('Sort by') }}">
                    @foreach ($orders as $key => $label)
                        @php $link = $listLink(['order' => $key]); @endphp
                        <a @if ($listState['order'] === $key) aria-current="true" @endif
                           class="px-3 py-1.5 rounded-field text-sm whitespace-nowrap transition-colors {{ $listState['order'] === $key ? 'bg-base-100 shadow-sm font-medium' : 'opacity-70 hover:opacity-100' }}"
                           href="{{ $link['href'] }}" hx-get="{{ $link['hx'] }}" hx-target="#reviews-list" hx-swap="outerHTML" hx-indicator="#reviews-list">{{ $label }}</a>
                    @endforeach
                </nav>
            </div>

            @if ($listShowFilters)
                {{-- The filters: a period, the stars, and two yes/no's. A chip
                     in force reads as pressed and its link takes it off. --}}
                <nav class="flex flex-wrap items-center gap-2" aria-label="{{ __('Filter reviews') }}">
                    @foreach ([null => __('All time'), 30 => __('Last 30 days'), 60 => __('Last 60 days'), 365 => __('Last year')] as $periodDays => $periodLabel)
                        @php $periodDays = $periodDays ?: null; $link = $listLink(['period' => $periodDays]); $on = $listState['period'] === $periodDays; @endphp
                        <a href="{{ $link['href'] }}" hx-get="{{ $link['hx'] }}" hx-target="#reviews-list" hx-swap="outerHTML" hx-indicator="#reviews-list"
                           @if ($on) aria-current="true" @endif
                           class="{{ $chip }} {{ $on ? 'border-primary bg-primary/10 text-primary font-medium' : 'border-base-300 hover:border-primary/50' }}">{{ $periodLabel }}</a>
                    @endforeach
                    <span class="w-px h-6 bg-base-300 mx-1 hidden sm:block" aria-hidden="true"></span>
                    @foreach ([5, 4, 3, 2, 1] as $starChip)
                        @php $on = $listState['stars'] === $starChip; $link = $listLink(['stars' => $on ? null : $starChip]); @endphp
                        <a href="{{ $link['href'] }}" hx-get="{{ $link['hx'] }}" hx-target="#reviews-list" hx-swap="outerHTML" hx-indicator="#reviews-list"
                           @if ($on) aria-current="true" @endif
                           aria-label="{{ trans_choice(':count star|:count stars', $starChip, ['count' => $starChip]) }}"
                           class="{{ $chip }} tabular-nums {{ $on ? 'border-primary bg-primary/10 text-primary font-medium' : 'border-base-300 hover:border-primary/50' }}">
                            {{ $starChip }}<x-icon name="star" size="sm" class="text-(--torgash-star) fill-current" />
                        </a>
                    @endforeach
                    <span class="w-px h-6 bg-base-300 mx-1 hidden sm:block" aria-hidden="true"></span>
                    @foreach (['buyers' => [__('Buyers only'), 'badge-check'], 'photos' => [__('With photos'), 'camera']] as $toggleKey => [$toggleLabel, $toggleIcon])
                        @php $on = $listState[$toggleKey]; $link = $listLink([$toggleKey => ! $on]); @endphp
                        <a href="{{ $link['href'] }}" hx-get="{{ $link['hx'] }}" hx-target="#reviews-list" hx-swap="outerHTML" hx-indicator="#reviews-list"
                           @if ($on) aria-current="true" @endif
                           class="{{ $chip }} {{ $on ? 'border-primary bg-primary/10 text-primary font-medium' : 'border-base-300 hover:border-primary/50' }}">
                            <x-icon :name="$toggleIcon" size="sm" /> {{ $toggleLabel }}
                        </a>
                    @endforeach
                </nav>
                @if ($listFiltered)
                    @php $link = $listLink(['stars' => null, 'period' => null, 'buyers' => false, 'photos' => false]); @endphp
                    <div class="flex items-center gap-3 text-sm">
                        <span class="opacity-60">{{ trans_choice('{0}No reviews match|{1}:count review found|[2,*]:count reviews found', $listFound, ['count' => $listFound]) }}</span>
                        <a href="{{ $link['href'] }}" hx-get="{{ $link['hx'] }}" hx-target="#reviews-list" hx-swap="outerHTML" hx-indicator="#reviews-list" class="link link-hover">{{ __('Reset') }}</a>
                    </div>
                @endif
            @endif
        </div>
    @endif

    {{-- What this reader has already voted on, for the whole page in ONE
         query — the `catalog.products.listed` idiom applied to a list that
         draws a pair of buttons per row. --}}
    @php $myVotes = app(\Torgash\Reviews\Votes::class)->mineFor($reviews, auth()->user()); @endphp

    {{-- People, one after another, divided by a line — not a stack of boxes. --}}
    <div class="flex flex-col divide-y divide-base-300">
        @foreach ($reviews as $review)
            @include('torgash.reviews::storefront.review', [
                'review' => $review,
                'criteria' => $criteria,
                'myVotes' => $myVotes,
                'reviewVariants' => $reviewVariants,
                'reviewPageProductId' => (int) $product->id,
            ])
        @endforeach
    </div>

    @if ($reviews->count() < $listFound)
        @php
            $more = min(\Torgash\Reviews\Listing::perPage(), $listFound - $reviews->count());
            $link = $listLink(['shown' => $listState['shown'] + \Torgash\Reviews\Listing::perPage()]);
        @endphp
        <div class="flex flex-col items-center gap-2 pt-5 border-t border-base-300">
            <span class="text-xs opacity-60">{{ __('Shown :shown of :total', ['shown' => $reviews->count(), 'total' => $listFound]) }}</span>
            <a href="{{ $link['href'] }}" hx-get="{{ $link['hx'] }}" hx-target="#reviews-list" hx-swap="outerHTML" hx-indicator="#reviews-list"
               class="btn btn-sm btn-outline">{{ trans_choice('Show :count more review|Show :count more reviews', $more, ['count' => $more]) }}</a>
        </div>
    @endif
</div>
