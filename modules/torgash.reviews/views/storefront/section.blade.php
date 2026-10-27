@php
    use Torgash\Reviews\Aggregates;
    use Torgash\Reviews\Criteria;
    use Torgash\Reviews\Eligibility;

    $aggregates = app(Aggregates::class);
    $row = $aggregates->for((int) $product->id);
    $count = (int) ($row->reviews_count ?? 0);
    $rating = (float) ($row->rating ?? 0);
    $spread = $aggregates->spread((int) $product->id);
    $criteriaAverages = $aggregates->criteria((int) $product->id);
    $criteria = app(Criteria::class)->forProduct($product);
    $refusal = app(Eligibility::class)->refusal(auth()->user(), $product);
    $rewardNote = trim((string) setting('torgash.reviews', 'reward_note', ''));

    // The NAME, not the dom id: <x-modal> prefixes it with "modal-", so a name
    // that already carried the prefix made a dialog called
    // "modal-modal-review-7" while the button looked for "modal-review-7" and
    // got null. One variable for the name, one expression for the id, and the
    // two cannot drift apart again.
    $modalName = 'review-'.$product->id;
    $modalId = 'modal-'.$modalName;

    // How many said they would recommend it, out of those who answered. A
    // percentage is the one number a shopper reads faster than an average, and
    // it is already counted — but it is only printed when somebody actually
    // answered the question, never as "0% recommend" because nobody was asked.
    $recommendAnswers = (int) ($row->recommend_yes ?? 0) + (int) ($row->recommend_neutral ?? 0) + (int) ($row->recommend_no ?? 0);
    $recommendShare = $recommendAnswers > 0 ? (int) round(($row->recommend_yes ?? 0) * 100 / $recommendAnswers) : null;

    // Warm at the top, cold at the bottom — the one place in this section
    // where colour carries meaning rather than decoration.
    $barColour = [5 => 'bg-success', 4 => 'bg-success/70', 3 => 'bg-(--torgash-star)', 2 => 'bg-(--torgash-star)/70', 1 => 'bg-error'];
@endphp

<div class="flex flex-col gap-8">
    {{-- The head: the mark, what it is made of, and the way in — the one
         part of the section drawn boldly; everything under it stays quiet.

         The spread is drawn from the STORED counts, not from the rows below:
         the list is the last twenty and the bars are about all of them, and a
         chart disagreeing with the number beside it reads as a shop massaging
         something. --}}
    <div class="rounded-box bg-base-200/70 p-6 lg:p-8 grid gap-8 md:grid-cols-[13rem_1fr] xl:grid-cols-[13rem_1fr_16rem] items-start">
        <div class="flex flex-col gap-4">
            <div>
                <div class="flex items-baseline gap-2">
                    <span class="text-6xl font-bold leading-none tracking-tight tabular-nums">{{ $count ? number_format($rating, 1) : '—' }}</span>
                    <span class="text-lg opacity-40">/ 5</span>
                </div>
                <div class="mt-3 flex items-center gap-0.5 text-(--torgash-star)" aria-hidden="true">
                    {{-- Half a star at a time, as the mark beside the price draws it. --}}
                    @php
                        $fullStars = (int) floor($rating);
                        $halfStar = ($rating - $fullStars) >= 0.25 && ($rating - $fullStars) < 0.75;
                        $litStars = $halfStar ? $fullStars : (int) round($rating);
                    @endphp
                    @for ($i = 1; $i <= 5; $i++)
                        @if ($i <= $litStars)
                            <x-icon name="star" size="lg" class="fill-current" />
                        @elseif ($halfStar && $i === $fullStars + 1)
                            <x-icon name="star-half" size="lg" class="fill-current" />
                        @else
                            <x-icon name="star" size="lg" class="opacity-25" />
                        @endif
                    @endfor
                </div>
                <div class="mt-2 text-sm opacity-60">
                    {{ trans_choice('{0}No reviews yet|{1}:count review|[2,*]:count reviews', $count, ['count' => $count]) }}
                </div>
            </div>

            @if ($refusal)
                <div class="text-sm opacity-60 leading-snug">{{ $refusal }}</div>
            @else
                <button type="button" class="btn btn-primary w-full"
                        data-open-dialog="{{ $modalId }}">
                    <x-icon name="pencil-line" size="sm" />
                    {{ __('Write a review') }}
                </button>
            @endif
        </div>

        @if ($count)
            {{-- How the marks spread: a bar per star, the count and its share. --}}
            <div class="flex flex-col gap-2.5 self-center">
                @foreach (array_reverse($spread, true) as $star => $n)
                    @php $share = $count > 0 ? (int) round($n * 100 / $count) : 0; @endphp
                    <div @class(['grid grid-cols-[2.25rem_1fr_4.5rem] items-center gap-3 text-sm', 'opacity-45' => $n === 0])>
                        <span class="flex items-center gap-1 tabular-nums">
                            {{ $star }}<x-icon name="star" size="sm" class="text-(--torgash-star) fill-current" />
                        </span>
                        <span class="h-2.5 rounded-full bg-base-300 overflow-hidden">
                            <span class="block h-full rounded-full {{ $barColour[$star] }}" style="width: {{ $share }}%"></span>
                        </span>
                        <span class="text-end tabular-nums"><span class="font-medium">{{ $n }}</span> <span class="opacity-50">· {{ $share }}%</span></span>
                    </div>
                @endforeach
            </div>

            @if ($recommendShare !== null || $criteriaAverages)
                <div class="flex flex-col gap-5 md:col-span-2 xl:col-span-1 xl:border-s xl:border-base-300 xl:ps-8">
                    @if ($recommendShare !== null)
                        <div class="flex items-center gap-4">
                            <div class="radial-progress text-success shrink-0" role="progressbar" aria-valuenow="{{ $recommendShare }}" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('would recommend it') }}"
                                 style="--value: {{ $recommendShare }}; --size: 4rem; --thickness: 5px;">
                                <span class="text-base-content font-semibold text-sm">{{ $recommendShare }}%</span>
                            </div>
                            <span class="text-sm leading-snug">{{ __('would recommend it') }}</span>
                        </div>
                    @endif

                    @if ($criteriaAverages)
                        <div class="flex flex-col gap-3">
                            @foreach ($criteria as $criterion)
                                @continue(! isset($criteriaAverages[$criterion->code]))
                                @php $value = (float) $criteriaAverages[$criterion->code]; @endphp
                                <div class="flex flex-col gap-1.5">
                                    <div class="flex items-baseline justify-between gap-3 text-sm">
                                        <span class="opacity-75 truncate">{{ $criterion->label() }}</span>
                                        <span class="font-semibold tabular-nums">{{ number_format($value, 1) }}</span>
                                    </div>
                                    <span class="h-1.5 rounded-full bg-base-300 overflow-hidden">
                                        <span class="block h-full rounded-full bg-(--torgash-star)" style="width: {{ round($value * 20) }}%"></span>
                                    </span>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        @endif
    </div>

    @if (session('status'))
        <div class="alert alert-success text-sm">{{ session('status') }}</div>
    @endif

    @foreach (['review', 'reply'] as $field)
        @if ($errors->has($field))
            <div class="alert alert-error text-sm">{{ $errors->first($field) }}</div>
        @endif
    @endforeach

    {{-- Nobody has reviewed it: an empty list with NO filter in force — a
         filter that leaves nothing is not "no reviews yet". --}}
    @if ($reviews->isEmpty() && ! \Torgash\Reviews\Listing::filtered($state ?? \Torgash\Reviews\Listing::state([])))
        <div class="rounded-box border border-dashed border-base-300 p-8 text-center">
            <div class="opacity-60 text-sm">{{ __('Nobody has reviewed this product yet.') }}</div>
            @if (! $refusal)
                <button type="button" class="btn btn-ghost btn-sm mt-2"
                        data-open-dialog="{{ $modalId }}">
                    {{ __('Be the first') }}
                </button>
            @endif
        </div>
    @else
        @php
            $sectionListing = app(\Torgash\Reviews\Listing::class);
            $sectionPhotos = setting('torgash.reviews', 'photo_strip', true) ? $sectionListing->photos((int) $product->id) : collect();
            $sectionHighlights = $sectionListing->highlights((int) $product->id, max($count, $reviews->count()));
        @endphp

        {{-- What customers photographed, every review at once — the strip a
             reader looks at before reading anybody. From two photographs on:
             one is already right there in its review. --}}
        @if ($sectionPhotos->count() > 1)
            <div class="flex flex-col gap-3">
                <h3 class="font-semibold">{{ __('Customers\' photos') }} <span class="opacity-50 font-normal">{{ $sectionPhotos->count() }}</span></h3>
                <div class="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:thin]" data-review-photos>
                    @foreach ($sectionPhotos as $sectionPhoto)
                        @php $sectionPhotoSize = \Torgash\Media\Gallery::dimensions($sectionPhoto); @endphp
                        <a href="{{ $sectionPhoto->getUrl() }}" target="_blank" rel="noopener"
                           data-review-photo data-width="{{ $sectionPhotoSize['width'] }}" data-height="{{ $sectionPhotoSize['height'] }}"
                           class="block w-24 h-24 shrink-0 rounded-box overflow-hidden bg-base-200 hover:opacity-90">
                            <img src="{{ \Torgash\Media\Gallery::thumbUrl($sectionPhoto, 160) }}" alt="" class="w-full h-full object-cover" loading="lazy" decoding="async">
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- The most helpful praise beside the most helpful complaint —
             Listing::highlights(): only from `highlights_from` reviews and
             only when both exist with a "helpful" vote behind them. --}}
        @if ($sectionHighlights)
            <div class="grid gap-4 md:grid-cols-2">
                @foreach (['positive' => [__('The most helpful positive review'), 'bg-success/8 border-success/25'], 'negative' => [__('The most helpful critical review'), 'bg-error/6 border-error/20']] as $highlightKey => [$highlightTitle, $highlightTone])
                    @php $highlight = $sectionHighlights[$highlightKey]; @endphp
                    <div class="rounded-box border {{ $highlightTone }} p-4 flex flex-col gap-2">
                        <div class="text-sm font-semibold">{{ $highlightTitle }}</div>
                        <div class="flex items-center gap-2 text-sm">
                            <span class="font-medium">{{ $highlight->author_name }}</span>
                            @if ($highlight->verified)
                                <x-icon name="badge-check" size="sm" class="text-success" />
                            @endif
                            <span class="flex items-center text-(--torgash-star) ms-auto" aria-label="{{ __(':rating out of 5', ['rating' => number_format((float) $highlight->rating, 1)]) }}">
                                @for ($i = 1; $i <= 5; $i++)
                                    <x-icon name="star" size="sm" class="{{ $i <= $highlight->stars() ? 'fill-current' : 'opacity-25' }}" />
                                @endfor
                            </span>
                        </div>
                        @if ($highlight->body)
                            <p class="text-sm leading-relaxed line-clamp-5 whitespace-pre-line">{{ $highlight->body }}</p>
                        @endif
                        <div class="mt-auto pt-1 text-xs opacity-60 flex items-center gap-1">
                            <x-icon name="thumbs-up" size="sm" />
                            {{ trans_choice(':count person found it helpful|:count people found it helpful', $highlight->helpful_yes, ['count' => $highlight->helpful_yes]) }}
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- The list and its controls are one partial, because a control
             REPLACES that partial when it is pressed: two pieces of markup
             would mean the swapped-in half arrives without the buttons that
             asked for it. --}}
        @include('torgash.reviews::storefront.list', [
            'product' => $product,
            'reviews' => $reviews,
            'criteria' => $criteria,
            'order' => $order,
            'state' => $state ?? null,
        ])
    @endif
</div>

{{-- What a photograph's click needs: the viewer's icons and words
     (lightbox.mjs draws none; the product gallery prints the same set, and the
     first of each name wins), and the one listener. --}}
<template data-gallery-icon="arrow-left" data-label="{{ __('Back') }}"><x-icon name="arrow-left" size="lg" /></template>
<template data-gallery-icon="image"><x-icon name="image" size="sm" /></template>
<template data-gallery-icon="zoom" data-label="{{ __('Zoom') }}"></template>
<template data-gallery-icon="x" data-label="{{ __('Close') }}"><x-icon name="x" size="lg" /></template>
<template data-gallery-icon="chevron-left" data-label="{{ __('Previous photo') }}"><x-icon name="chevron-left" size="xl" /></template>
<template data-gallery-icon="chevron-right" data-label="{{ __('Next photo') }}"><x-icon name="chevron-right" size="xl" /></template>
<script nonce="{{ csp_nonce() }}" type="module" src="{{ module_asset('torgash.reviews', 'photos.mjs') }}"></script>

{{-- The form is a popup, because the page it sits on is long and the person
     writing has just finished reading it — sending them back to the top, or
     growing the section by a screenful of empty boxes, are the two worse
     answers. Only drawn for somebody who may actually write one. --}}
@if (! $refusal)
    @include('torgash.reviews::storefront.form', [
        'product' => $product,
        'criteria' => $criteria,
        'modalName' => $modalName,
        'rewardNote' => $rewardNote,
    ])
@endif
