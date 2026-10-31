{{--
    The phone header's wishlist and basket — drawn only when the bottom bar
    is switched off (theme setting `tabbar`), standing in for its two tabs.
    The entries are the bar's own (`storefront.tabbar`, what the modules
    add), and each count keeps the bar's id `#tabbar-badge-{key}`: the bar is
    not on the page, so the id is still one, and an add refreshes it out of
    band exactly as it refreshed the tab. Personal (the counts are the
    visitor's), drawn with @personal by the header.
--}}
@php
    $mobileIconItems = collect(hook_filter('storefront.tabbar', [], ['storefront' => $storefront ?? null]))
        ->filter(fn ($tab) => in_array($tab['key'] ?? null, ['wishlist', 'cart'], true) && ! empty($tab['url']))
        ->sortBy('order')->values();
@endphp
@foreach ($mobileIconItems as $mobileIconItem)
    <a href="{{ $mobileIconItem['url'] }}" class="torgash-mobile-icon btn btn-square" aria-label="{{ $mobileIconItem['label'] }}" title="{{ $mobileIconItem['label'] }}"
       @if (! empty($mobileIconItem['active'])) aria-current="page" @endif>
        <span class="relative inline-flex">
            <x-icon :name="$mobileIconItem['icon']" size="lg" />
            <span id="tabbar-badge-{{ $mobileIconItem['key'] }}" @class(['badge badge-primary torgash-tabbar-badge', 'hidden' => empty($mobileIconItem['badge'])])>{{ $mobileIconItem['badge'] ?? '' }}</span>
        </span>
    </a>
@endforeach
