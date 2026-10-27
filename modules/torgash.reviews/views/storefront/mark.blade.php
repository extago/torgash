{{-- The mark itself: stars, the number, and how many people said it.

     Two sizes of the same thing rather than two components — a card and a
     product page print the same fact, and two templates would drift into two
     different roundings of it.

     The stars are drawn from the stored average, half a star at a time: a 4.3
     that draws four full stars is a shop rounding in its own favour, and a
     reader notices. The number beside them is what actually carries the
     meaning, which is why it is never hidden. --}}
@php
    $full = (int) floor($rating);
    $half = ($rating - $full) >= 0.25 && ($rating - $full) < 0.75;
    $rounded = $half ? $full : (int) round($rating);
@endphp

<span class="inline-flex items-center gap-1.5 {{ $compact ? 'text-xs' : 'text-sm' }}"
      @if ($count > 0) title="{{ __(':rating out of 5', ['rating' => number_format($rating, 1)]) }}" @endif>
    <span class="inline-flex items-center {{ $count === 0 ? 'text-base-content/25' : 'text-(--torgash-star)' }}" aria-hidden="true">
        @for ($i = 1; $i <= 5; $i++)
            @if ($i <= $rounded)
                <x-icon name="star" :size="$compact ? 'sm' : 'md'" class="fill-current" />
            @elseif ($half && $i === $full + 1)
                <x-icon name="star-half" :size="$compact ? 'sm' : 'md'" class="fill-current" />
            @else
                <x-icon name="star" :size="$compact ? 'sm' : 'md'" :class="$count > 0 ? 'opacity-30' : ''" />
            @endif
        @endfor
    </span>
    @if ($count === 0)
        {{-- The product page's invitation: nothing is claimed, the stars are all empty. --}}
        <a href="#reviews" class="link link-hover opacity-70">{{ __('Be the first to review') }}</a>
    @else
        <span class="font-semibold">{{ number_format($rating, 1) }}</span>
        {{-- On the product page the count leads to the reviews themselves. --}}
        @if ($compact)
            <span class="opacity-60">{{ trans_choice('{1}:count review|[2,*]:count reviews', $count, ['count' => $count]) }}</span>
        @else
            <a href="#reviews" class="opacity-60 link link-hover">{{ trans_choice('{1}:count review|[2,*]:count reviews', $count, ['count' => $count]) }}</a>
        @endif
    @endif
</span>
