{{-- Stars for a mark, half a star at a time — the same rounding the product
     reviews draw (a 4.3 drawn as four full stars is the shop rounding in its
     own favour). `$srRating`, `$srSize` (sm|md|lg). --}}
@php
    $srFull = (int) floor((float) $srRating);
    $srHalf = ((float) $srRating - $srFull) >= 0.25 && ((float) $srRating - $srFull) < 0.75;
    $srRounded = $srHalf ? $srFull : (int) round((float) $srRating);
@endphp
<span class="inline-flex items-center text-(--torgash-star)" aria-hidden="true">
    @for ($i = 1; $i <= 5; $i++)
        @if ($i <= $srRounded)
            <x-icon name="star" :size="$srSize ?? 'sm'" class="fill-current" />
        @elseif ($srHalf && $i === $srFull + 1)
            <x-icon name="star-half" :size="$srSize ?? 'sm'" class="fill-current" />
        @else
            <x-icon name="star" :size="$srSize ?? 'sm'" class="opacity-30" />
        @endif
    @endfor
</span>
