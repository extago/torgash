{{-- The header in two rows (theme setting "Header layout"): a thin strip
     and a compact main row. Its colours are the other setting
     (`header_palette`): the dark bar is torgash-bar — dark in both themes,
     so daisyUI components inside it need the base tokens remapped (app.css)
     — or the page's own palette, a line under each row.

     The header itself is `contents`: its two rows are the page's own boxes,
     so each can stick on its own ($headerStick, worked out by the layout) —
     only the main row, as most shops do it, or both. A sticky row sticks
     only inside its parent, and a parent that is exactly as tall as the two
     rows would carry the main row away with it. The mega menu's state stays
     on <header>; Alpine does not care that it draws no box. --}}
@php
    $hdDark = theme_setting('header_palette') !== 'light';
    // One colour scheme per row, literal class names (Tailwind reads them).
    $hdBar = $hdDark ? 'torgash-bar bg-neutral text-neutral-content' : 'bg-base-100 text-base-content';
    $hdMuted = $hdDark ? 'text-neutral-content/80' : 'text-base-content/70';
    // The shop's contacts (zone storefront.header.after_search): beside the
    // search, or up in the strip beside the language (`header_contacts`).
    $hdContactsUp = theme_setting('header_contacts') === 'strip';
@endphp
<header class="contents"
        x-data="{ mega: false, megaActive: {{ \Illuminate\Support\Js::from($megaItems->first()?->id) }} }"
        @keydown.escape.window="mega = false"
        @click.outside="mega = false">
    {{-- Utility strip: secondary controls only, kept out of the main row so
         logo/search/cart get uncontested room. Above the main row (z-40), so
         its drop-downs open over it. --}}
    <div @class([$hdBar, 'torgash-header-bar relative z-40 border-b', 'border-white/10' => $hdDark, 'border-base-300' => ! $hdDark, 'sticky top-0' => $headerStick === 'all'])>
        <div class="container mx-auto max-w-(--torgash-container) flex items-center justify-end gap-1 px-4 h-10">
            {{-- The strip's far LEFT is where a shop says where the customer is
                 (the town, the shop they collect from) — a module draws it
                 through storefront.header.start. --}}
            <div class="mr-auto flex items-center gap-5 min-w-0 text-sm {{ $hdMuted }}">
                <div class="flex items-center shrink-0">{!! hook_render('storefront.header.start', ['storefront' => $storefront, 'header' => $hdDark ? 'dark' : 'light']) !!}</div>
                {{-- Two zones of the strip (PLAN §39): after the town, and
                     before the language and currency. --}}
                <div class="hidden md:flex items-center gap-5 min-w-0">{!! zone('storefront.header.top_start', ['storefront' => $storefront]) !!}</div>
            </div>
            <div class="flex items-center gap-5 shrink-0 text-sm {{ $hdMuted }} mr-2">{!! zone('storefront.header.top_end', ['storefront' => $storefront]) !!}</div>
            @if ($hdContactsUp)
                <div class="flex items-center shrink-0">{!! zone('storefront.header.after_search', ['storefront' => $storefront]) !!}</div>
            @endif
            @include('layouts.partials.header-switchers', ['switcherTrigger' => $hdDark ? 'btn btn-ghost btn-sm gap-1 normal-case text-neutral-content/70 hover:text-neutral-content' : 'btn btn-ghost btn-sm gap-1 normal-case font-normal text-base-content/70 hover:text-base-content'])

            @include('layouts.partials.mode-switch', ['modeSwitchClass' => $hdDark ? 'text-neutral-content/70 hover:text-neutral-content' : 'btn-touch text-base-content/70 hover:text-base-content'])
        </div>
    </div>

    {{-- Main row: brand, catalog entry point, search, cart — the row that
         stays on screen, and what the mega menu hangs from. --}}
    <div @class([$hdBar, 'torgash-header-bar relative z-30', 'shadow-md' => $hdDark, 'border-b border-base-300 shadow-sm' => ! $hdDark, 'sticky top-0' => $headerStick === 'main', 'sticky top-10' => $headerStick === 'all'])>
    <div class="torgash-header-main container mx-auto max-w-(--torgash-container) flex items-center gap-3 md:gap-4 px-4 h-16">
        <a href="{{ route('catalog.home') }}" class="text-xl font-bold whitespace-nowrap shrink-0">
            <x-theme.logo :on-dark="$hdDark" />
        </a>

        @if (! theme_setting('catalog_button', true))
            {{-- No button: the strip under the header holds the catalogue. --}}
        @elseif ($megaItems->isNotEmpty())
            {{-- Opens the mega menu rendered at the bottom of this header; the
                 shared x-data lives on <header> so both sides see it. --}}
            {{-- relative z-[21]: above the backdrop (z-20 in the header's own
                 stacking context), so the button that closes the menu is not
                 dimmed with the page. --}}
            <button type="button" data-mega-toggle @click="mega = !mega"
                    class="hidden sm:inline-flex btn btn-primary btn-sm gap-2 shrink-0 relative z-[21]"
                    :aria-expanded="mega ? 'true' : 'false'">
                <x-icon name="layout-grid" x-show="!mega" />
                <x-icon name="x" x-cloak x-show="mega" />
                {{ __('Catalog') }}
            </button>
        @else
            <a href="{{ route('catalog.index') }}" class="hidden sm:inline-flex btn btn-primary btn-sm gap-2 shrink-0">
                <x-icon name="layout-grid" /> {{ __('Catalog') }}
            </a>
        @endif

        @if ($hasBrands && theme_setting('brands_button', false))
            <a href="{{ route('catalog.brands.index') }}" class="hidden lg:inline-flex btn btn-ghost btn-sm gap-2 shrink-0">
                {{ __('Brands') }}
            </a>
        @endif

        {{-- data-live-search: a search module may attach its window here;
             without one the mark means nothing and the form still submits. --}}
        <form method="GET" action="{{ \Illuminate\Support\Facades\Route::has('catalog.search') ? route('catalog.search') : '#' }}"
              role="search" data-live-search
              class="relative grow min-w-0">
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search products') }}" aria-label="{{ __('Search products') }}"
                   @class(['input w-full pl-4 pr-11 focus:border-primary', 'bg-white/10 border-white/15 placeholder:text-neutral-content/50' => $hdDark, 'bg-base-200/60 border-base-300' => ! $hdDark])>
            <button type="submit" aria-label="{{ __('Search') }}"
                    class="absolute inset-y-0 right-0 btn btn-square btn-primary h-full w-10 min-h-0 rounded-l-none border-0">
                <x-icon name="search" />
            </button>
            {{-- Extension point INSIDE the form: controls that ride the
                 query (a search module's "site / catalogue" switch). --}}
            {!! hook_render('storefront.search.controls', ['storefront' => $storefront]) !!}
        </form>

        {{-- Extension point: contact block (phone, messengers, callback) sits
             right after the search, like the reference storefronts. --}}
        @unless ($hdContactsUp)
            {!! zone('storefront.header.after_search', ['storefront' => $storefront]) !!}
        @endunless


        @auth
            @include('layouts.partials.account-menu')
        @else
            <a href="{{ route('account.login') }}" class="btn btn-ghost btn-sm gap-1.5 normal-case shrink-0">
                <x-icon name="user" /> <span class="hidden lg:inline">{{ __('Sign in') }}</span>
            </a>
        @endauth

        {{-- Extension point: icons with counts beside the basket (a wishlist,
             a comparison) — each module draws its own. --}}
        {!! hook_render('storefront.header.icons', ['storefront' => $storefront]) !!}

        @if (app(\App\Core\Modules\ModuleManager::class)->isEnabled('torgash.cart'))
            @include('torgash.cart::partials.badge')
        @endif
    </div>

    @if ($megaItems->isNotEmpty() && theme_setting('catalog_button', true))
        @include('layouts.partials.mega-menu', ['megaItems' => $megaItems])
    @endif
    </div>
</header>
