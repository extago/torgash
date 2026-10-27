@extends('layouts.storefront')

{{--
    The shop's reviews: the numbers first (the average, how many would
    recommend the shop and buy again, the marks per criterion, the spread),
    the shop's marks on other sites, then the reviews with the shop's answers.
    The form is a popup, opened from the summary and from the empty state.
--}}
@section('content')
    <x-breadcrumbs />

    <div class="flex flex-wrap items-end justify-between gap-3 mb-5">
        <h1 class="text-2xl font-bold">{{ __('Shop reviews') }}</h1>
        @if (! $refusal)
            <button type="button" class="btn btn-primary" data-open-dialog="modal-shop-review">
                <x-icon name="pencil-line" size="sm" /> {{ __('Leave a review') }}
            </button>
        @endif
    </div>

    @if (session('status'))
        <div class="alert alert-success mb-5"><x-icon name="circle-check" /> {{ session('status') }}</div>
    @endif

    @if ($summary['count'] > 0)
        <section class="mb-6 bg-base-100 border border-base-300 rounded-box p-5 flex flex-col gap-5">
            <div class="flex items-center gap-4">
                <div class="text-5xl font-bold leading-none">{{ number_format($summary['rating'], 1) }}</div>
                <div class="flex flex-col gap-1">
                    @include('torgash.shopreviews::storefront._stars', ['srRating' => $summary['rating'], 'srSize' => 'md'])
                    <span class="text-sm opacity-60">{{ trans_choice('{1}:count review|[2,*]:count reviews', $summary['count'], ['count' => $summary['count']]) }}</span>
                </div>
            </div>

            @if ($summary['recommend'] !== null || $summary['will_return'] !== null)
                <div class="flex flex-wrap gap-x-8 gap-y-3">
                    @if ($summary['recommend'] !== null)
                        <div class="flex items-baseline gap-2">
                            <span class="text-3xl font-bold text-success">{{ $summary['recommend'] }}%</span>
                            <span class="text-sm opacity-70">{{ __('recommend the shop') }}</span>
                        </div>
                    @endif
                    @if ($summary['will_return'] !== null)
                        <div class="flex items-baseline gap-2">
                            <span class="text-3xl font-bold text-success">{{ $summary['will_return'] }}%</span>
                            <span class="text-sm opacity-70">{{ __('will buy here again') }}</span>
                        </div>
                    @endif
                </div>
            @endif

            <div class="grid gap-x-8 gap-y-5 md:grid-cols-2">
                @if ($summary['criteria'] !== [])
                    <div class="flex flex-col gap-2">
                        @foreach ($summary['criteria'] as $srRow)
                            <div class="grid grid-cols-[minmax(6rem,9rem)_1fr_2.5rem] items-center gap-3 text-sm">
                                <span class="truncate">{{ $srRow['criterion']->label() }}</span>
                                <span class="h-2 rounded-full bg-base-300 overflow-hidden"><span class="block h-full rounded-full bg-(--torgash-star)" style="width: {{ $srRow['rating'] / 5 * 100 }}%"></span></span>
                                <b class="tabular-nums">{{ number_format($srRow['rating'], 1) }}</b>
                            </div>
                        @endforeach
                    </div>
                @endif
                <div class="flex flex-col gap-2">
                    @foreach ($summary['spread'] as $srStars => $srCount)
                        <div class="grid grid-cols-[minmax(6rem,9rem)_1fr_2.5rem] items-center gap-3 text-sm">
                            <span class="inline-flex items-center gap-1">{{ $srStars }} <x-icon name="star" size="sm" class="fill-current text-(--torgash-star)" /></span>
                            <span class="h-2 rounded-full bg-base-300 overflow-hidden"><span class="block h-full rounded-full bg-(--torgash-star)" style="width: {{ $summary['count'] ? $srCount / $summary['count'] * 100 : 0 }}%"></span></span>
                            <span class="tabular-nums opacity-60">{{ $srCount }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    @if ($platforms->isNotEmpty())
        <section class="mb-6">
            <h2 class="text-lg font-semibold mb-3">{{ __('Our rating on other sites') }}</h2>
            <div class="grid gap-3 grid-cols-[repeat(auto-fill,minmax(12rem,1fr))]">
                @foreach ($platforms as $srPlatform)
                    @php($srTag = $srPlatform->url ? 'a' : 'div')
                    <{{ $srTag }} @if ($srPlatform->url) href="{{ $srPlatform->url }}" target="_blank" rel="noopener nofollow" @endif
                        class="flex flex-col gap-1 rounded-box border border-base-300 bg-base-100 p-4 hover:border-primary/50 transition-colors">
                        <span class="font-semibold truncate">{{ $srPlatform->name }}</span>
                        @if ($srPlatform->rating !== null)
                            <span class="inline-flex items-center gap-1.5">
                                @include('torgash.shopreviews::storefront._stars', ['srRating' => $srPlatform->rating, 'srSize' => 'sm'])
                                <b>{{ number_format($srPlatform->rating, 1) }}</b>
                            </span>
                        @endif
                        @if ($srPlatform->reviews_count)
                            <span class="text-xs opacity-60">{{ trans_choice('{1}:count review|[2,*]:count reviews', $srPlatform->reviews_count, ['count' => $srPlatform->reviews_count]) }}</span>
                        @endif
                    </{{ $srTag }}>
                @endforeach
            </div>
        </section>
    @endif

    @if ($refusal)
        <div class="alert mb-5">
            <x-icon name="log-in" />
            <span>{{ $refusal }}</span>
            <a href="{{ route('login') }}" class="btn btn-sm">{{ __('Sign in') }}</a>
        </div>
    @endif

    @if ($reviews->isEmpty())
        <div class="bg-base-100 border border-base-300 rounded-box p-8 text-center flex flex-col items-center gap-3">
            <x-icon name="message-square-heart" size="xl" class="opacity-40" />
            <p class="opacity-70">{{ __('No reviews yet — tell others how your order went.') }}</p>
            @if (! $refusal)
                <button type="button" class="btn btn-primary" data-open-dialog="modal-shop-review">{{ __('Leave a review') }}</button>
            @endif
        </div>
    @else
        <div class="flex flex-col divide-y divide-base-300 bg-base-100 border border-base-300 rounded-box">
            @foreach ($reviews as $shopReview)
                <div class="p-4 sm:p-5">
                    @include('torgash.shopreviews::storefront._review', ['shopReview' => $shopReview])
                </div>
            @endforeach
        </div>

        <div class="mt-5">{{ $reviews->links() }}</div>
    @endif

    @if (! $refusal)
        @include('torgash.shopreviews::storefront._form')
    @endif
@endsection
