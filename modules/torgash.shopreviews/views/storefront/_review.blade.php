{{-- One review of the shop: the round mark with the first letter, the name,
     town and date, the stars, what was said, and the shop's answer under it.
     `$shopReview`. --}}
<article class="flex gap-3 sm:gap-4 scroll-mt-24" id="review-{{ $shopReview->id }}">
    <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-full font-semibold text-white" style="background: oklch(62% 0.12 {{ $shopReview->hue() }})" aria-hidden="true">{{ $shopReview->initial() }}</span>

    <div class="flex-1 min-w-0 flex flex-col gap-2">
        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5">
            <span class="font-semibold">{{ $shopReview->author_name }}@if ($shopReview->city)<span class="font-normal opacity-60">, {{ $shopReview->city }}</span>@endif</span>
            <time class="text-xs opacity-50" datetime="{{ $shopReview->created_at?->toDateString() }}">{{ $shopReview->created_at?->isoFormat('D MMMM YYYY') }}</time>
        </div>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
            <span class="inline-flex items-center gap-1.5">
                @include('torgash.shopreviews::storefront._stars', ['srRating' => $shopReview->rating, 'srSize' => 'sm'])
                <b>{{ number_format((float) $shopReview->rating, 1) }}</b>
            </span>
            @if ($shopReview->verified)
                <span class="inline-flex items-center gap-1 text-success text-xs"><x-icon name="badge-check" size="sm" /> {{ __('Customer of the shop') }}</span>
            @endif
            @if ($shopReview->source)
                <span class="text-xs opacity-60">{{ __('From :source', ['source' => $shopReview->source]) }}</span>
            @endif
        </div>

        @if ($shopReview->ratings->isNotEmpty() || $shopReview->recommend || $shopReview->will_return)
            <div class="flex flex-wrap gap-1.5">
                @foreach ($shopReview->ratings as $srScore)
                    @if ($srScore->criterion)
                        <span class="badge badge-ghost gap-1">{{ $srScore->criterion->label() }} <b>{{ $srScore->value }}</b></span>
                    @endif
                @endforeach
                @if ($shopReview->recommend)
                    <span class="badge badge-success badge-soft gap-1"><x-icon name="thumbs-up" size="sm" /> {{ __('Recommends the shop') }}</span>
                @endif
                @if ($shopReview->will_return)
                    <span class="badge badge-success badge-soft gap-1"><x-icon name="repeat" size="sm" /> {{ __('Will buy again') }}</span>
                @endif
            </div>
        @endif

        @if ($shopReview->pros)
            <p class="text-sm"><span class="font-semibold text-success">{{ __('Pros') }}:</span> <span class="whitespace-pre-line">{{ $shopReview->pros }}</span></p>
        @endif
        @if ($shopReview->cons)
            <p class="text-sm"><span class="font-semibold text-error">{{ __('Cons') }}:</span> <span class="whitespace-pre-line">{{ $shopReview->cons }}</span></p>
        @endif
        @if ($shopReview->body)
            <p class="whitespace-pre-line leading-relaxed">{{ $shopReview->body }}</p>
        @endif

        @if ($shopReview->reply)
            <div class="mt-1 rounded-box bg-base-200/70 border-s-4 border-primary/40 px-4 py-3">
                <div class="flex flex-wrap items-baseline gap-x-3 text-sm">
                    <span class="font-semibold inline-flex items-center gap-1.5"><x-icon name="store" size="sm" class="text-primary" /> {{ $shopReview->reply_author ?: __('Shop') }}</span>
                    @if ($shopReview->replied_at)
                        <time class="text-xs opacity-50" datetime="{{ $shopReview->replied_at->toDateString() }}">{{ $shopReview->replied_at->isoFormat('D MMMM YYYY') }}</time>
                    @endif
                </div>
                <p class="text-sm whitespace-pre-line mt-1">{{ $shopReview->reply }}</p>
            </div>
        @endif
    </div>
</article>
