@props([
    // How big the drawn placeholder's icon is: sm (a basket row), md (a card), lg (the product page).
    'size' => 'md',
])
{{--
    What stands where a product has no photograph — ONE component, so a card,
    the product page, the basket, a kit and the wishlist cannot each invent
    their own grey letter. The picture is the theme's `no_image` setting (the
    shop's upload, or the theme's own file); with none, a quiet drawn frame
    in the page's own colours, so it reads right in the dark theme too.

    Never a URL handed to a feed or an email: `primaryImageUrl()` stays null
    for a product with no photo, because a marketplace rejects placeholders.
    It fills its box — the caller sizes it (class "w-full h-full", size "lg").
--}}
@php
    $noImageUrl = theme_image('no_image');
    $noImageIcon = ['sm' => 'h-6 w-6', 'md' => 'h-12 w-12', 'lg' => 'h-20 w-20'][$size] ?? 'h-12 w-12';
@endphp
@if ($noImageUrl)
    <img src="{{ $noImageUrl }}" alt="" {{ $attributes->merge(['class' => 'torgash-no-image object-contain']) }} loading="lazy" decoding="async">
@else
    <div {{ $attributes->merge(['class' => 'torgash-no-image flex items-center justify-center bg-base-200/60']) }} role="img" aria-label="{{ __('No photo yet') }}">
        {{-- Faded as ONE picture (opacity on the svg), never by a translucent
             colour: each stroke would be translucent on its own and the
             places where two cross would show darker. --}}
        <x-icon name="image" :class="$noImageIcon.' opacity-20'" />
    </div>
@endif
