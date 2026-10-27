{{--
    The header in three rows (theme setting "Header layout", PLAN §37): a strip
    of links that scrolls away (or stays too, with "the whole header"), a TALL
    main row — logo, catalogue, search, the shop's contacts, icons with their
    word under them — which stays on screen when "keep the header on screen"
    is on, and, a switch of its own, the strip of top categories. Its colours
    are the other setting (`header_palette`): the dark bar (torgash-bar, the
    base tokens remapped — app.css) or the page's own palette.

    Module counters (wishlist, comparison) are labelled from their own `title`
    by .torgash-header-icons in app.css, so no module has to know this header.
--}}
@php
    $hdDark = theme_setting('header_palette') !== 'light';
    // One colour scheme per row, literal class names (Tailwind reads them).
    $hdBar = $hdDark ? 'torgash-bar bg-neutral text-neutral-content' : 'bg-base-100 text-base-content';
    $hdLine = $hdDark ? 'border-white/10' : 'border-base-300';
    // The shop's contacts (zone storefront.header.after_search): beside the
    // search, or up in the strip beside the language (`header_contacts`).
    $hdContactsUp = theme_setting('header_contacts') === 'strip';
@endphp
<div @class([$hdBar, 'border-b text-sm relative z-40', $hdLine, 'sticky top-0' => $headerStick === 'all'])>
    <div @class(['container mx-auto max-w-(--torgash-container) px-4 h-10 flex items-center gap-5', 'text-neutral-content/80' => $hdDark, 'text-base-content/70' => ! $hdDark])>
        <div class="flex items-center min-w-0 shrink-0">{!! hook_render('storefront.header.start', ['storefront' => $storefront, 'header' => $hdDark ? 'dark' : 'light']) !!}</div>
        {{-- Two zones of the strip (PLAN §39): after the town, and before the
             language and currency. The theme puts the "top" menu in the first;
             anything the shop moves here is a block like any other. --}}
        <div class="hidden md:flex items-center gap-5 flex-1 min-w-0">{!! zone('storefront.header.top_start', ['storefront' => $storefront]) !!}</div>
        <div class="grow md:hidden"></div>
        <div class="flex items-center gap-5 shrink-0">{!! zone('storefront.header.top_end', ['storefront' => $storefront]) !!}</div>
        @if ($hdContactsUp)
            <div class="flex items-center shrink-0">{!! zone('storefront.header.after_search', ['storefront' => $storefront]) !!}</div>
        @endif

        @include('layouts.partials.header-switchers', ['switcherTrigger' => $hdDark ? 'btn btn-ghost btn-sm gap-1 normal-case font-normal text-neutral-content/70 hover:text-neutral-content' : 'btn btn-ghost btn-sm gap-1 normal-case font-normal'])

        @include('layouts.partials.mode-switch', ['modeSwitchClass' => $hdDark ? 'text-neutral-content/70 hover:text-neutral-content' : 'btn-touch'])
    </div>
</div>

{{-- The main row — the one that sticks. It is also what the mega menu
     hangs from (relative, with the menu's state in its x-data). --}}
<header @class([$hdBar, 'torgash-header-bar z-30 relative', 'shadow-md' => $hdDark, 'border-b border-base-300' => ! $hdDark, 'sticky top-0' => $headerStick === 'main', 'sticky top-10' => $headerStick === 'all'])
        x-data="{ mega: false, megaActive: {{ \Illuminate\Support\Js::from($megaItems->first()?->id) }} }"
        @keydown.escape.window="mega = false" @click.outside="mega = false">
    <div class="container mx-auto max-w-(--torgash-container) px-4 h-20 flex items-center gap-4 lg:gap-6">
        <a href="{{ route('catalog.home') }}" class="flex items-center shrink-0">
            <x-theme.logo :on-dark="$hdDark" height="h-10" class="text-2xl font-bold tracking-tight" />
        </a>

        @if (! theme_setting('catalog_button', true))
            {{-- No button: the strip under the header holds the catalogue. --}}
        @elseif ($megaItems->isNotEmpty())
            <button type="button" data-mega-toggle @click="mega = !mega" class="hidden sm:inline-flex btn btn-primary px-6 gap-2 shrink-0 relative z-[21]" :aria-expanded="mega ? 'true' : 'false'">
                <x-icon name="layout-grid" x-show="!mega" />
                <x-icon name="x" x-cloak x-show="mega" />
                {{ __('Catalog') }}
            </button>
        @else
            <a href="{{ route('catalog.index') }}" class="hidden sm:inline-flex btn btn-primary px-6 gap-2 shrink-0">
                <x-icon name="layout-grid" /> {{ __('Catalog') }}
            </a>
        @endif

        <form method="GET" action="{{ \Illuminate\Support\Facades\Route::has('catalog.search') ? route('catalog.search') : '#' }}"
              role="search" data-live-search class="relative grow min-w-0">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search products') }}" aria-label="{{ __('Search products') }}"
                   @class(['input w-full pl-4 pr-12 focus:border-primary', 'bg-white/10 border-white/15 placeholder:text-neutral-content/50' => $hdDark, 'input-bordered' => ! $hdDark])>
            <button type="submit" aria-label="{{ __('Search') }}"
                    class="absolute inset-y-0 right-0 btn btn-square btn-primary h-full w-11 min-h-0 rounded-l-none border-0">
                <x-icon name="search" />
            </button>
            {!! hook_render('storefront.search.controls', ['storefront' => $storefront]) !!}
        </form>

        {{-- The shop's contacts (the phone and its drop-down) — the same
             place the two-row header gives them, right after the search. --}}
        @unless ($hdContactsUp)
            <div class="hidden xl:flex items-center shrink-0">{!! zone('storefront.header.after_search', ['storefront' => $storefront]) !!}</div>
        @endunless

        <nav class="torgash-header-icons flex items-center gap-1 shrink-0" aria-label="{{ __('Account') }}">
            @auth
                @include('layouts.partials.account-menu', ['accountMenuStacked' => true])
            @else
                <a href="{{ route('account.login') }}" class="btn btn-ghost" title="{{ __('Sign in') }}">
                    <x-icon name="user" size="lg" />
                </a>
            @endauth
            {!! hook_render('storefront.header.icons', ['storefront' => $storefront]) !!}
            @if (app(\App\Core\Modules\ModuleManager::class)->isEnabled('torgash.cart'))
                @include('torgash.cart::partials.badge')
            @endif
        </nav>
    </div>

    @if ($megaItems->isNotEmpty() && theme_setting('catalog_button', true))
        @include('layouts.partials.mega-menu', ['megaItems' => $megaItems])
    @endif
</header>
