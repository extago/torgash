{{-- The latest published reviews of the shop as cards, for the home page,
     with the average and a link to all of them. Drawn from nothing when there
     is nothing to show. --}}
@php
    $srSummary = app(\Torgash\ShopReviews\Stats::class)->summary();
    $srLatest = \Torgash\ShopReviews\Models\ShopReview::query()->shown()
        ->when((float) ($settings['min_rating'] ?? 0) > 0, fn ($q) => $q->where('rating', '>=', (float) $settings['min_rating']))
        ->latest('created_at')->latest('id')
        ->limit(max(1, min(12, (int) ($settings['limit'] ?? 3))))
        ->get();
@endphp
@if ($srLatest->isNotEmpty())
    <section class="flex flex-col gap-4">
        <div class="flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="text-xl font-bold">{{ trim((string) ($settings['heading'] ?? '')) ?: __('Shop reviews') }}</h2>
            <a href="{{ route('shopreviews.index') }}" class="inline-flex items-center gap-2 link link-hover text-sm">
                @include('torgash.shopreviews::storefront._stars', ['srRating' => $srSummary['rating'], 'srSize' => 'sm'])
                <b>{{ number_format($srSummary['rating'], 1) }}</b>
                <span class="opacity-70">{{ __('All :count reviews', ['count' => $srSummary['count']]) }}</span>
            </a>
        </div>
        <div class="grid gap-4 grid-cols-[repeat(auto-fill,minmax(16rem,1fr))]">
            @foreach ($srLatest as $srReview)
                <a href="{{ route('shopreviews.index') }}#review-{{ $srReview->id }}" class="flex flex-col gap-3 rounded-box border border-base-300 bg-base-100 p-4 hover:border-primary/50 transition-colors">
                    <div class="flex items-center gap-3">
                        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-full font-semibold text-white" style="background: oklch(62% 0.12 {{ $srReview->hue() }})" aria-hidden="true">{{ $srReview->initial() }}</span>
                        <div class="min-w-0">
                            <div class="font-semibold truncate">{{ $srReview->author_name }}</div>
                            <div class="text-xs opacity-50">{{ $srReview->created_at?->isoFormat('D MMMM YYYY') }}</div>
                        </div>
                    </div>
                    @include('torgash.shopreviews::storefront._stars', ['srRating' => $srReview->rating, 'srSize' => 'sm'])
                    <p class="text-sm line-clamp-5 whitespace-pre-line">{{ $srReview->body ?: $srReview->pros }}</p>
                </a>
            @endforeach
        </div>
    </section>
@endif
