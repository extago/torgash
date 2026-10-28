<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    {{-- Title, description, canonical, hreflang, OpenGraph and the page's
         structured data, all out of App\Core\Seo\PageMeta — see the component.
         A page fills that in; nothing prints a head tag of its own. --}}
    <x-seo.head />
    {{-- The theme's settings as this storefront chose them (PLAN §37). --}}
    <x-theme.style />
    <script nonce="{{ csp_nonce() }}">
        // "A script runs here": what only a script can open (the phone's menu,
        // the filter sheet) is folded away only under this class, so a browser
        // without one still gets every control in the page's own flow.
        document.documentElement.classList.add('torgash-js');
        (function () {
            try {
                var saved = localStorage.getItem('torgash-theme');
                if (saved === 'torgash-dark' || saved === 'torgash-light') {
                    document.documentElement.setAttribute('data-theme', saved);
                } else {
                    // Nothing chosen yet: the shop's default (theme setting
                    // "Appearance"); "auto" leaves it to the system.
                    var mode = document.querySelector('meta[name="torgash-mode"]');
                    if (mode && (mode.content === 'light' || mode.content === 'dark')) {
                        document.documentElement.setAttribute('data-theme', 'torgash-' + mode.content);
                    }
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/vendor/htmx.min.js') }}" defer></script>
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/vendor/alpine.min.js') }}" defer></script>
    <script nonce="{{ csp_nonce() }}">
        // A page open longer than the session: the token it holds is dead, so
        // every HTMX action would fail silently (419). A fresh page has a fresh one.
        document.addEventListener('htmx:responseError', (e) => { if (e.detail.xhr && e.detail.xhr.status === 419) location.reload(); });
        document.addEventListener('htmx:configRequest', (e) => {
            e.detail.headers['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]').content;
        });
        // How wide the page's scrollbar is: 100vw counts it, so a section
        // "edge to edge" would otherwise overhang the window by half of it
        // on each side (app.css, `.torgash-section-full`).
        (() => {
            const measure = () => document.documentElement.style.setProperty('--torgash-scrollbar', (window.innerWidth - document.documentElement.clientWidth) + 'px');
            document.addEventListener('DOMContentLoaded', measure);
            window.addEventListener('resize', measure);
        })();
    </script>
    @stack('head')
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/actions.js') }}" defer></script>
</head>
@php
    // What sticks at the top, which is what a sticky strip, the product
    // page's section bar or a corner notice has to clear. Three rows can:
    // the thin top strip (h-10) only with "the whole header", the main row
    // (dark h-16, light h-20) whenever the header is kept, and the category
    // strip (h-12) by a switch of its own. `--torgash-strip-top` is where
    // that strip stands: under whatever of the header stays.
    //
    // The cart and checkout get a quiet header instead (theme setting):
    // nothing on it leads away from paying, and nothing of it stays on screen.
    $quietHeader = theme_setting('quiet_checkout_header', true)
        && request()->routeIs('cart.index', '*.cart.index', 'checkout.create', '*.checkout.create');
    $headerStick = $quietHeader || ! theme_setting('header_sticky', true) ? 'none' : (theme_setting('header_sticky_rows', 'main') === 'all' ? 'all' : 'main');
    $mainRowRem = theme_setting('header') === 'three_rows' ? 5 : 4;
    $stuckHeaderRem = match ($headerStick) { 'all' => 2.5 + $mainRowRem, 'main' => $mainRowRem, default => 0 };
    $stripSticks = ! $quietHeader && theme_setting('category_strip') && theme_setting('category_strip_sticky', false);
    $headerHeight = ($stuckHeaderRem + ($stripSticks ? 3 : 0)).'rem';
@endphp
{{-- Which buttons a card's photo shows, per device (theme settings
     card_corner_*; the rules are app.css, .torgash-corner-more). --}}
<body class="torgash-storefront min-h-screen bg-base-200 flex flex-col max-lg:pb-16"
      data-card-corner-phone="{{ theme_setting('card_corner_phone', 'more') }}" data-card-corner-tablet="{{ theme_setting('card_corner_tablet', 'all') }}" data-card-corner-computer="{{ theme_setting('card_corner_computer', 'hover') }}"
      style="--torgash-header-h: {{ $headerHeight }}; --torgash-strip-top: {{ $stuckHeaderRem }}rem">
    {{-- The three slots a module needs to reach the page without patching a
         theme: a counter's <noscript> pixel goes here, its script in
         `core.head`, a chat widget in `core.footer`. --}}
    {!! hook_render('core.body_start') !!}
    @php
        $storefront = current_storefront();
        $languages = \App\Models\Language::whereIn('code', $storefront?->locales ?? [])->where('enabled', true)->orderBy('sort')->get();
        $currencies = \App\Models\Currency::whereIn('code', $storefront?->currencies ?? [])->where('enabled', true)->orderBy('sort')->get();
        $activeCurrency = session('currency', $storefront?->default_currency);
        $hasBrands = \Illuminate\Support\Facades\Route::has('catalog.brands.index')
            && \Torgash\Catalog\Models\FeatureValue::whereNotNull('slug')
                ->whereHas('feature', fn ($q) => $q->where('purpose', 'brand'))
                ->exists();

        // Same page, other locale. Every locale's route shape now exists at
        // once (see App\Core\Storefronts\StorefrontRoutes), so route() by the
        // other locale's name would work — but this stays path-based on
        // purpose: it needs no route name or parameters, so it is correct for
        // any URL the visitor can be on, including paginated and filtered ones
        // whose query string has to survive the switch. Strip this request's
        // own locale segment (if any) to get the bare suffix, then rebuild it
        // per language (no prefix for the default locale, /xx/ for the rest).
        $pathSegments = array_values(array_filter(explode('/', request()->path()), fn ($s) => $s !== ''));
        if (! empty($pathSegments) && in_array($pathSegments[0], $storefront?->locales ?? [], true)) {
            array_shift($pathSegments);
        }
        $pathSuffix = implode('/', $pathSegments);
        // The address bar's own query, not Symfony's normalised one:
        // getQueryString() parses and rebuilds it, which numbers a list
        // (`feature[8][]=22` comes back as `feature[8][0]=22`). Same page
        // either way, but switching language would silently re-spell the URL
        // the visitor is looking at.
        $query = (string) request()->server('QUERY_STRING');

        $localeLinks = [];
        foreach ($languages as $lang) {
            $prefix = $lang->code === $storefront?->default_locale ? '' : $lang->code.'/';
            $localeLinks[$lang->code] = url($prefix.$pathSuffix).($query ? '?'.$query : '');
        }

        // What the "Catalogue" button opens and what the strip under the
        // header shows (PLAN §39): menus chosen in the theme's settings, built
        // by torgash.menus — whose category items open into their
        // subcategories and can keep only those with something to buy. With
        // no menu module, nothing chosen, or the chosen menu gone: the category
        // tree itself, in the SAME shape (Torgash\Menus\Node's properties), so
        // each partial draws one thing.
        $menuBuilder = class_exists(\Torgash\Menus\MenuBuilder::class) ? app(\Torgash\Menus\MenuBuilder::class) : null;
        $menuFromSetting = function (string $setting, string $default) use ($menuBuilder) {
            $code = (string) theme_setting($setting, $default);

            return $code !== '' && $menuBuilder && $menuBuilder->exists($code) ? collect($menuBuilder->nodes($code)) : null;
        };
        $categoryTree = app(\Torgash\Catalog\Support\CategoryTree::class)->all()->filter(fn ($c) => $c->isListed())->groupBy(fn ($c) => (int) $c->parent_id);
        $categoryNodes = function (int $parentId, int $levels) use (&$categoryNodes, $categoryTree) {
            return $categoryTree->get($parentId, collect())->map(fn ($category) => (object) [
                'id' => 'c'.$category->id, 'kind' => 'category', 'label' => (string) $category->t('name'),
                'href' => route('catalog.category', $category->slug),
                'icon' => $category->lucideIcon(), 'image' => $category->iconImageUrl(),
                'badge' => null, 'badgeStyle' => 'primary', 'highlight' => false, 'newTab' => false, 'cssClass' => null,
                'count' => null, 'active' => false, 'trail' => false, 'showOn' => 'all',
                'children' => $levels > 1 ? $categoryNodes($category->id, $levels - 1)->all() : [],
            ])->values();
        };

        // Dividers and headings have no place among the departments on the left.
        $megaItems = ($menuFromSetting('catalog_menu', 'catalog') ?? $categoryNodes(0, 3))
            ->filter(fn ($node) => $node->href !== null)->values();
    @endphp

    {{-- Above everything, the header included, on the phone too — "the shop
         is being filled", a holiday notice. Edge to edge: a block that wants
         the page's width brings `container mx-auto` itself. --}}
    @php $pageTop = zone('storefront.page.top', ['storefront' => $storefront]); @endphp
    @if (trim($pageTop) !== '')
        <div class="torgash-page-top">{!! $pageTop !!}</div>
    @endif

    {{-- Which header is a THEME SETTING (PLAN §37), two axes: the layout (two
         rows or three) picks the partial, the palette (`header_palette`: the
         dark bar or the page's colours) is read inside it. The strip of top
         categories under either is a switch of its own. Each partial is a
         whole header, and a theme that wants another overrides or adds one. --}}
    {{-- The desktop headers from `lg` up; below it the phone's own header,
         the menu drawer and the tab bar (PLAN §38). --}}
    <div class="hidden lg:contents">
        @if ($quietHeader)
            @include('layouts.partials.header-quiet')
        @else
            @include(theme_setting('header') === 'three_rows' ? 'layouts.partials.header-three-rows' : 'layouts.partials.header-two-rows')

            @if (theme_setting('category_strip'))
                @include('layouts.partials.category-strip')
            @endif
        @endif
    </div>
    @include('layouts.partials.mobile-header')
    @include('layouts.partials.mobile-drawer')

    <main class="container mx-auto max-w-(--torgash-container) px-(--torgash-gutter) py-4 lg:py-6 grow">
        @yield('content')
    </main>

    {{-- Its bands and settings: layouts/partials/footer.blade.php. --}}
    @include('layouts.partials.footer')

    {{-- Under the footer, its counterpart. --}}
    @php $pageBottom = zone('storefront.page.bottom', ['storefront' => $storefront]); @endphp
    @if (trim($pageBottom) !== '')
        <div class="torgash-page-bottom">{!! $pageBottom !!}</div>
    @endif

    {{-- The counterpart of @stack('head') the admin layout already has: a
         module shipping storefront JS (the town picker is the first) pushes it
         here rather than repeating a <script> in every view that uses it. --}}
    {{-- Its counts are the visitor's own: drawn for each of them on a page
         kept ready for guests (PLAN §61). --}}
    @personal('layouts.partials.mobile-tabbar', ['storefront' => $storefront], true)

    {{-- Always, not only when the popover component pushed it: a shop's own
         HTML block (the contacts in the header) opens a popover with plain
         `popovertarget` markup. Unless x-popover already pushed it under the
         same key: two tags were two downloads on every page. --}}
    @unless ($__env->hasRenderedOnce('popover-anchor'))
        <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/popover-anchor.js') }}" defer></script>
    @endunless
    @stack('scripts')

    {!! hook_render('core.footer') !!}
</body>
</html>
