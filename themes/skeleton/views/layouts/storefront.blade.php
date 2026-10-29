{{--
    The skeleton theme's one template: every storefront page is a module's
    view that @extends('layouts.storefront'), so this file is the whole theme.
    Lines marked REQUIRED are what the engine relies on; leave one out and
    something stops working without an error anywhere. Everything else —
    the header, the footer, the classes — is yours to redraw.

    Not here, on purpose (see themes/default for how it does them): the
    language and currency switch, the catalogue menu, the phone's drawer and
    tab bar, a sticky header, settings for the configurator.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- REQUIRED: HTMX sends it with every request (below). --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- REQUIRED: title, description, canonical, hreflang, OpenGraph and
         structured data, all from App\Core\Seo\PageMeta. Print no head tag
         of your own. --}}
    <x-seo.head />
    {{-- REQUIRED: the theme's settings as CSS, and the script that writes
         the colour scheme on <html> (the visitor's choice, else the
         system's) — every dark-scheme rule in app.css hangs on it. It is
         also where a child theme of this one gets its configurator values. --}}
    <x-theme.style />
    {{-- REQUIRED, every script: the page's content security policy runs only
         a <script> carrying this request's nonce. --}}
    <script nonce="{{ csp_nonce() }}">
        // REQUIRED: what only a script can open (the filter sheet on a phone)
        // is folded away only under this class.
        document.documentElement.classList.add('torgash-js');
    </script>
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/vendor/htmx.min.js') }}" defer></script>
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/vendor/alpine.min.js') }}" defer></script>
    <script nonce="{{ csp_nonce() }}">
        // REQUIRED: without the token every HTMX action fails with 419, and a
        // page left open past its session needs a fresh one.
        document.addEventListener('htmx:responseError', (e) => { if (e.detail.xhr && e.detail.xhr.status === 419) location.reload(); });
        document.addEventListener('htmx:configRequest', (e) => {
            e.detail.headers['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]').content;
        });
    </script>
    {{-- REQUIRED: modules push their storefront CSS/JS here. --}}
    @stack('head')
    {{-- REQUIRED: data-confirm and the other declarative actions. --}}
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/actions.js') }}" defer></script>
</head>
{{-- REQUIRED: `torgash-storefront` carries the storefront's scale of controls (app.css). --}}
<body class="torgash-storefront min-h-screen bg-base-200 flex flex-col" style="--torgash-header-h: 0rem; --torgash-strip-top: 0rem">
    {{-- REQUIRED: a counter's <noscript> pixel, consent banners. --}}
    {!! hook_render('core.body_start') !!}
    @php $storefront = current_storefront(); @endphp

    <header class="bg-base-100 border-b border-base-300">
        <div class="container mx-auto max-w-(--torgash-container) px-(--torgash-gutter) py-3 flex flex-wrap items-center gap-x-4 gap-y-3">
            <a href="{{ route('catalog.home') }}" class="shrink-0">
                <x-theme.logo :on-dark="false" height="h-9" class="text-xl font-bold tracking-tight" />
            </a>

            <a href="{{ route('catalog.index') }}" class="btn btn-ghost hidden sm:inline-flex">{{ __('Catalog') }}</a>

            {{-- `data-live-search` is what torgash.search hangs its live window on;
                 `storefront.search.controls` is where it adds its own fields. --}}
            <form method="GET" action="{{ \Illuminate\Support\Facades\Route::has('catalog.search') ? route('catalog.search') : '#' }}"
                  role="search" data-live-search class="relative order-last md:order-none basis-full md:basis-auto grow min-w-0">
                <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search products') }}" aria-label="{{ __('Search products') }}"
                       class="input input-bordered w-full pr-12">
                <button type="submit" aria-label="{{ __('Search') }}"
                        class="absolute right-1 top-1/2 -translate-y-1/2 btn btn-ghost btn-sm btn-square">
                    <x-icon name="search" />
                </button>
                {!! hook_render('storefront.search.controls', ['storefront' => $storefront]) !!}
            </form>

            {{-- Seams modules draw the header through: the town (`start`),
                 the shop's contacts block, wishlist and comparison counters. --}}
            <div class="flex items-center gap-1 ml-auto md:ml-0 shrink-0">
                {!! hook_render('storefront.header.start', ['storefront' => $storefront, 'header' => 'skeleton']) !!}
                {!! zone('storefront.header.after_search', ['storefront' => $storefront]) !!}
                <a href="{{ auth()->check() ? route('account.dashboard') : route('account.login') }}" class="btn btn-ghost btn-sm btn-square"
                   title="{{ auth()->check() ? __('Account') : __('Sign in') }}">
                    <x-icon name="user" size="lg" />
                </a>
                {!! hook_render('storefront.header.icons', ['storefront' => $storefront]) !!}
                @if (app(\App\Core\Modules\ModuleManager::class)->isEnabled('torgash.cart'))
                    @include('torgash.cart::partials.badge')
                @endif
            </div>
        </div>
    </header>

    <main class="container mx-auto max-w-(--torgash-container) px-(--torgash-gutter) py-4 lg:py-6 grow">
        @yield('content')
    </main>

    {{-- `torgash-bar`: blocks written for a dark footer read on it. --}}
    <footer class="torgash-bar bg-neutral text-neutral-content/80 text-sm">
        <div class="container mx-auto max-w-(--torgash-container) px-(--torgash-gutter) py-6 flex flex-col gap-4">
            @php $footerBottom = zone('storefront.footer.bottom', ['storefront' => $storefront, 'place' => 'footer']); @endphp
            @if (trim($footerBottom) !== '')
                {!! $footerBottom !!}
            @else
                <div>© {{ date('Y') }} {{ $storefront?->name }}</div>
            @endif
        </div>
    </footer>

    {{-- REQUIRED: popovers (a shop's own HTML block opens them with plain
         `popovertarget` markup), then what modules push, then the footer
         hook — chat widgets, snippets, the consent switch. --}}
    @unless ($__env->hasRenderedOnce('popover-anchor'))
        <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/popover-anchor.js') }}" defer></script>
    @endunless
    @stack('scripts')
    {!! hook_render('core.footer') !!}
</body>
</html>
