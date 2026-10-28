{{--
    The quiet header of the cart and checkout (theme setting "A quiet header on
    the cart and checkout pages"): where the customer is on the left, the shop
    in the middle, how to reach it on the right — the three things somebody
    about to pay may still need. No search, no catalogue, no menus: each is a
    way out of the purchase. It does not stick; the page is short and the
    summary column does.

    The town and the contacts come through the same seams the full headers
    use (`storefront.header.start`, the `storefront.header.after_search` zone),
    so a module that draws them there draws them here too.
--}}
<header class="bg-base-100 border-b border-base-300">
    <div class="container mx-auto max-w-(--torgash-container) px-(--torgash-gutter) h-20 grid grid-cols-[1fr_auto_1fr] items-center gap-6">
        <div class="flex items-center min-w-0 text-sm">
            {!! hook_render('storefront.header.start', ['storefront' => $storefront, 'header' => 'quiet']) !!}
        </div>

        <a href="{{ route('catalog.home') }}" class="text-2xl font-bold tracking-tight whitespace-nowrap">
            <x-theme.logo :on-dark="false" />
        </a>

        <div class="flex items-center justify-end min-w-0">
            {!! zone('storefront.header.after_search', ['storefront' => $storefront]) !!}
        </div>
    </div>
</header>
