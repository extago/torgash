@props(['row' => false])
{{--
    A card's shape while the real one is on its way — only where a part of
    the page arrives AFTER it (HTMX: "show more", a dialog). The storefront is
    drawn on the server, so a page never starts as skeletons: they would be a
    request, a layout shift and a promise of content that is already there.

        <x-skeleton-card />          a product card (square picture, two lines, a price)
        <x-skeleton-card row />      a listing row

    aria-hidden: a screen reader hears the busy region (aria-busy), not grey boxes.
--}}
@if ($row)
    <div {{ $attributes->class('flex items-center gap-4 p-3') }} aria-hidden="true">
        <div class="skeleton w-20 h-20 shrink-0"></div>
        <div class="flex-1 flex flex-col gap-2">
            <div class="skeleton h-4 w-3/4"></div>
            <div class="skeleton h-3 w-1/2"></div>
        </div>
        <div class="skeleton h-6 w-24 shrink-0"></div>
    </div>
@else
    <div {{ $attributes->class('flex flex-col gap-2 p-4') }} aria-hidden="true">
        <div class="skeleton aspect-square w-full mb-2"></div>
        <div class="skeleton h-4 w-full"></div>
        <div class="skeleton h-4 w-2/3"></div>
        <div class="skeleton h-3 w-1/3 mt-1"></div>
        <div class="skeleton h-6 w-1/2 mt-1"></div>
    </div>
@endif
