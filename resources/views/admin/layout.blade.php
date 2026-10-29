<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', __('Admin')) · Torgash</title>
    <script nonce="{{ csp_nonce() }}">
        (function () {
            try {
                var saved = localStorage.getItem('torgash-theme');
                if (saved === 'torgash-dark' || saved === 'torgash-light') {
                    document.documentElement.setAttribute('data-theme', saved);
                }
            } catch (e) {}
        })();
    </script>
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
    @stack('head')
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/vendor/htmx.min.js') }}" defer></script>
    {{-- Alpine Sort (MIT, SortableJS inside): `x-sort` on a list, and the
         dragging works under a finger. Before Alpine, which it plugs into. --}}
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/vendor/alpine-sort.min.js') }}" defer></script>
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/vendor/alpine.min.js') }}" defer></script>
    {{-- A table's status menu opening past the table's own scroll box. --}}
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/admin-dropdowns.js') }}" defer></script>
    {{-- Wide tables and strips of tabs on a narrow screen. --}}
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/admin-scroll.js') }}" defer></script>
    <script nonce="{{ csp_nonce() }}">
        // A page open longer than the session: the token it holds is dead, so
        // every HTMX action would fail silently (419). A fresh page has a fresh one.
        document.addEventListener('htmx:responseError', (e) => { if (e.detail.xhr && e.detail.xhr.status === 419) location.reload(); });
        document.addEventListener('htmx:configRequest', (e) => {
            e.detail.headers['X-CSRF-TOKEN'] = document.querySelector('meta[name="csrf-token"]').content;
        });
    </script>
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/actions.js') }}" defer></script>
</head>
<body class="torgash-admin min-h-screen bg-base-200">
{{-- `data-sidebar`: this person's own choice of a whole sidebar or a strip
     of icons from `lg` (admin_preferences, PLAN §62) — drawn folded by the
     server, so nothing jumps. --}}
<div class="drawer lg:drawer-open" data-sidebar="{{ \App\Core\Admin\Preferences::get(auth()->user(), 'sidebar') === 'strip' ? 'strip' : 'full' }}">
    <input id="admin-drawer" type="checkbox" class="drawer-toggle">

    <div class="drawer-content flex flex-col">
        {{-- The bar runs the full width, its contents only as wide as the
             page below (max-w-[1600px], the same side padding as <main>), so
             the search and the bell line up with the cards they belong to. --}}
        {{-- On a phone the header scrolls away with the page (app.css, "The
             admin on a phone"): the screen is short, and an edit screen's own
             bar carries the way back. --}}
        <header class="admin-header bg-base-100 border-b border-base-300 sticky top-0 z-30 px-(--admin-gutter)">
          <div class="navbar px-0 mx-auto w-full max-w-[1600px]">
            <div class="flex-none lg:hidden">
                <label for="admin-drawer" class="btn btn-square btn-ghost btn-sm">
                    <x-icon name="menu" size="lg" />
                </label>
            </div>

            {{-- The page's own name is not repeated up here — it heads the page
                 below. The bar holds what every page shares: the way to the
                 shop, the search, what is waiting, and who is looking. --}}
            <div class="flex-1 basis-0 px-2 min-w-0">
                <a href="{{ url('/') }}" target="_blank" rel="noopener" class="btn btn-ghost btn-sm gap-1.5 -ms-3">
                    <x-icon name="store" />
                    <span class="hidden sm:inline">{{ __('Open the storefront') }}</span>
                    <x-icon name="external-link" size="sm" class="opacity-50" />
                </a>
            </div>

            {{-- "Where is that set?" (App\Core\Admin\AdminSearch). A GET form
                 first — Enter with scripts off lands on the search page — and
                 HTMX fills the dropdown as the person types. `/` or Ctrl+K from
                 anywhere that is not a text field puts the cursor here. --}}
            <div class="flex-none relative me-1" x-data="adminSearch()"
                 @keydown.window="shortcut($event)" @click.outside="open = false">
                <a href="{{ route('admin.search') }}" class="btn btn-ghost btn-circle btn-sm md:hidden" title="{{ __('Search screens and settings') }}" aria-label="{{ __('Search screens and settings') }}">
                    <x-icon name="search" size="lg" />
                </a>
                <form method="GET" action="{{ route('admin.search') }}" class="hidden md:block" @submit="go($event)">
                    <label class="input input-sm input-bordered flex items-center gap-2 w-72 xl:w-[28rem]">
                        <x-icon name="search" class="opacity-50" />
                        <input type="search" name="q" x-ref="input" autocomplete="off" class="grow"
                               placeholder="{{ __('Search screens and settings') }}"
                               hx-get="{{ route('admin.search') }}" hx-trigger="input changed delay:200ms, search"
                               hx-target="#admin-search-results"
                               @focus="open = true" @input="open = true"
                               @keydown.escape="open = false; $el.blur()"
                               @keydown.down.prevent="move(1)">
                        <kbd class="kbd kbd-xs opacity-50">/</kbd>
                    </label>
                </form>
                <div id="admin-search-results" x-show="open" style="display: none"
                     class="absolute end-0 mt-2 w-[28rem] max-w-[calc(100vw-2rem)] max-h-[70vh] overflow-y-auto bg-base-100 border border-base-300 rounded-box shadow-lg z-50"
                     @keydown.down.prevent="move(1)" @keydown.up.prevent="move(-1)"
                     @keydown.escape="open = false; $refs.input.focus()">
                    @include('admin.search.results', ['query' => '', 'results' => []])
                </div>
            </div>
            <script nonce="{{ csp_nonce() }}">
                function adminSearch() {
                    return {
                        open: false,
                        links() {
                            return [...this.$root.querySelectorAll('[data-admin-search-results] a')];
                        },
                        move(step) {
                            const links = this.links();
                            if (! links.length) return;
                            const next = links.indexOf(document.activeElement) + step;
                            if (next < 0) return this.$refs.input.focus();
                            links[Math.min(next, links.length - 1)].focus();
                        },
                        // Enter opens the first result — but only a result for
                        // what is in the box NOW, not the list from three
                        // keystrokes ago that HTMX has not replaced yet.
                        go(event) {
                            const list = this.$root.querySelector('[data-admin-search-results]');
                            if (list && list.dataset.query === this.$refs.input.value.trim() && this.links()[0]) {
                                event.preventDefault();
                                window.location = this.links()[0].href;
                            }
                        },
                        shortcut(event) {
                            const key = (event.key || '').toLowerCase();
                            const typing = event.target.closest && event.target.closest('input, textarea, select, [contenteditable]');
                            if ((key === '/' && ! typing) || (key === 'k' && (event.ctrlKey || event.metaKey))) {
                                if (getComputedStyle(this.$refs.input).display === 'none' || ! this.$refs.input.offsetParent) return;
                                event.preventDefault();
                                this.$refs.input.focus();
                                this.$refs.input.select();
                            }
                        },
                    };
                }
            </script>

            <div class="flex-1 basis-0 flex items-center justify-end gap-1">
                @include('admin.partials.notifications')

                {{-- The content language, the currency of amounts and the
                     colour scheme stand in the bar on a wide screen; below
                     it (a phone, a tablet held upright) there is no room for
                     them, and the same choices are in the profile menu below.
                     Under a finger they stay there a little longer, buttons
                     being bigger (app.css, .admin-bar-choices). --}}
                <div class="admin-bar-choices hidden lg:contents">
                @php $contentLanguages = \App\Models\Language::where('enabled', true)->orderBy('sort')->get(); @endphp
                @if ($contentLanguages->count() > 1)
                    <div class="dropdown dropdown-end">
                        <div tabindex="0" role="button" class="btn btn-ghost btn-sm gap-1.5 normal-case" title="{{ __('Content language — which translation admin forms edit') }}">
                            <x-icon name="globe" class="opacity-60" />
                            {{ strtoupper(admin_content_locale()) }}
                            <x-icon name="chevron-down" size="sm" class="opacity-50" />
                        </div>
                        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-44 mt-3 p-2 z-50 border border-base-300">
                            <li class="menu-title text-xs">{{ __('Content language') }}</li>
                            @foreach ($contentLanguages as $lang)
                                <li>
                                    <a href="{{ route('admin.content-locale.set', ['locale' => $lang->code]) }}"
                                       @class(['font-semibold text-primary' => admin_content_locale() === $lang->code])>
                                        {{ $lang->native_name }}
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                {{-- Which currency the admin READS money in. Beside the content
                     language because it is the same kind of thing: a property of
                     whoever is looking, not of anything stored. "As stored" is
                     the default on purpose — it is the only mode in which the
                     number on the screen is the number in the database, and it
                     is what an inline price edit types into. --}}
                @php $shopCurrencies = app(\App\Core\Support\Currencies::class)->all(); @endphp
                @if (count($shopCurrencies) > 1)
                    <div class="dropdown dropdown-end">
                        <div tabindex="0" role="button" class="btn btn-ghost btn-sm gap-1.5 normal-case" title="{{ __('Which currency amounts are shown in') }}">
                            <x-icon name="banknote" class="opacity-60" />
                            {{ admin_currency() ?? __('Initial') }}
                            <x-icon name="chevron-down" size="sm" class="opacity-50" />
                        </div>
                        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-56 mt-3 p-2 z-50 border border-base-300">
                            <li class="menu-title text-xs">{{ __('Show amounts in') }}</li>
                            {{-- The empty option is "Initial" — the currency each amount is stored in, and the default. --}}
                            @foreach (array_merge([''], array_keys($shopCurrencies)) as $code)
                                {{-- The BUTTON has to be the whole row, not a
                                     label inside it. daisyUI lays a menu item
                                     (`li > *`, here the form) out as a grid
                                     whose single column is sized to its
                                     content, so `w-full` on the button
                                     resolved to 59px of a 206px row: measured,
                                     ten of twelve clicks across the visible
                                     item landed on the form and did nothing,
                                     which is what "I have to click it several
                                     times" is. `grid-cols-1` stretches the
                                     column, and the padding moves off the form
                                     onto the button so the whole row is the
                                     target. It stays a POST — a GET switcher
                                     gets followed by the browser's own link
                                     prefetcher. --}}
                                <li>
                                    <form method="POST" action="{{ route('admin.currency.set') }}" class="p-0 grid-cols-1">
                                        @csrf
                                        <input type="hidden" name="currency" value="{{ $code }}">
                                        <button type="submit" class="w-full text-left px-3 py-1.5"
                                                @class(['font-semibold text-primary' => admin_currency() === ($code ?: null)])>
                                            {{ $code ?: __('Initial') }}
                                            <span class="opacity-50 text-xs">{{ $code ? $shopCurrencies[$code]->symbol : shop_currency() }}</span>
                                        </button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <label class="swap swap-rotate btn btn-ghost btn-circle btn-sm" title="{{ __('Dark theme') }}">
                    <input type="checkbox" class="theme-controller" value="torgash-dark" data-theme-toggle aria-label="{{ __('Dark theme') }}">
                    <x-icon name="sun" size="lg" class="swap-off" />
                    <x-icon name="moon" size="lg" class="swap-on" />
                </label>
                </div>

                <div class="dropdown dropdown-end">
                    <div tabindex="0" role="button" class="btn btn-ghost btn-sm gap-2 normal-case pl-1.5">
                        {{-- daisyUI 5 calls it avatar-placeholder: the old class no
                             longer centres the letter. mb_: a Cyrillic name's first
                             byte is half a letter. --}}
                        <div class="avatar avatar-placeholder">
                            <div class="bg-primary text-primary-content rounded-full w-7">
                                <span class="text-xs font-semibold">{{ mb_strtoupper(mb_substr(auth()->user()?->name ?? '?', 0, 1)) }}</span>
                            </div>
                        </div>
                        <span class="hidden sm:inline">{{ auth()->user()?->name }}</span>
                        <x-icon name="chevron-down" size="sm" class="opacity-50" />
                    </div>
                    <div tabindex="0" class="dropdown-content bg-base-100 rounded-box shadow-md w-52 max-lg:w-64 max-lg:max-h-[calc(100dvh-5rem)] max-lg:overflow-y-auto mt-3 p-2 z-50 border border-base-300">
                    {{-- A narrow screen's share of the bar: the same three
                         choices as above, as buttons. Links, and a form per
                         currency as in the bar — nothing here belongs to
                         another form. --}}
                    <div class="admin-menu-choices lg:hidden flex flex-col gap-3 px-2 pt-1 pb-3 mb-1 border-b border-base-300">
                        @if ($contentLanguages->count() > 1)
                            <div>
                                <div class="text-xs opacity-60 mb-1.5">{{ __('Content language') }}</div>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach ($contentLanguages as $lang)
                                        <a href="{{ route('admin.content-locale.set', ['locale' => $lang->code]) }}"
                                           @class(['btn btn-sm', 'btn-primary' => admin_content_locale() === $lang->code])>{{ strtoupper($lang->code) }}</a>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        @if (count($shopCurrencies) > 1)
                            <div>
                                <div class="text-xs opacity-60 mb-1.5">{{ __('Show amounts in') }}</div>
                                <div class="flex flex-wrap gap-1.5">
                                    @foreach (array_merge([''], array_keys($shopCurrencies)) as $code)
                                        <form method="POST" action="{{ route('admin.currency.set') }}">
                                            @csrf
                                            <input type="hidden" name="currency" value="{{ $code }}">
                                            <button type="submit" @class(['btn btn-sm', 'btn-primary' => admin_currency() === ($code ?: null)])>{{ $code ?: __('Initial') }}</button>
                                        </form>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                        <label class="flex items-center justify-between gap-3 cursor-pointer text-sm">
                            {{ __('Dark theme') }}
                            <input type="checkbox" class="toggle toggle-sm theme-controller" value="torgash-dark" data-theme-toggle>
                        </label>
                    </div>
                    <ul class="menu menu-sm w-full p-0">
                        <li class="menu-title text-xs px-3 pt-1">{{ __('Signed in as') }}</li>
                        <li class="disabled px-3 pb-1 text-sm opacity-70">{{ auth()->user()?->email }}</li>
                        <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>

                        {{-- Whatever a module puts in the staff member's own menu:
                             their security page, their notification preferences, an
                             API token of their own. A LIST of items, exactly like
                             core.account.nav on the storefront, so the dropdown's
                             classes and its active state stay core's and a module
                             never learns this markup. Each item is
                             ['label' => …, 'url' => …, 'icon' => …, 'active' => bool].
                             Things ABOUT THE PERSON go here; a section of the shop
                             goes in the sidebar through the manifest. --}}
                        @foreach (hook_filter('core.admin.user_nav', []) as $item)
                            <li>
                                <a href="{{ $item['url'] }}" @class(['active' => ! empty($item['active'])])>
                                    <x-icon :name="$item['icon'] ?? 'circle'" /> {{ $item['label'] }}
                                </a>
                            </li>
                        @endforeach

                        @if ($contentLanguages->count() > 1)
                            <li class="menu-title text-xs">{{ __('Interface language') }}</li>
                            @foreach ($contentLanguages as $lang)
                                <li>
                                    <a href="{{ route('admin.interface-locale.set', ['locale' => $lang->code]) }}"
                                       @class(['font-semibold text-primary' => app()->getLocale() === $lang->code])>
                                        {{ $lang->native_name }}
                                    </a>
                                </li>
                            @endforeach
                            <li class="menu-title p-0 my-1" role="separator"><span class="block h-px bg-base-300"></span></li>
                        @endif
                        <li>
                            <form method="POST" action="{{ route('admin.logout') }}" class="p-0 grid-cols-1">
                                @csrf
                                <button type="submit" class="flex items-center gap-2 w-full px-3 py-1.5">
                                    <x-icon name="log-out" /> {{ __('Logout') }}
                                </button>
                            </form>
                        </li>
                    </ul>
                    </div>
                </div>
                {{-- Both switches of the colour scheme, the bar's and the
                     profile menu's, say the same thing and move together. --}}
                <script nonce="{{ csp_nonce() }}">
                    (function () {
                        var toggles = document.querySelectorAll('[data-theme-toggle]');
                        var current = document.documentElement.getAttribute('data-theme')
                            || (matchMedia('(prefers-color-scheme: dark)').matches ? 'torgash-dark' : 'torgash-light');
                        toggles.forEach(function (toggle) {
                            toggle.checked = current === 'torgash-dark';
                            toggle.addEventListener('change', function () {
                                var theme = toggle.checked ? 'torgash-dark' : 'torgash-light';
                                document.documentElement.setAttribute('data-theme', theme);
                                toggles.forEach(function (other) { other.checked = toggle.checked; });
                                try { localStorage.setItem('torgash-theme', theme); } catch (e) {}
                            });
                        });
                    })();
                </script>
            </div>
          </div>
        </header>

        {{-- Centred and bounded: on a wide screen the page stands in the
             middle with quiet margins rather than hugging the sidebar with a
             void on its right, and a 4K screen does not stretch a form into
             lines nobody can read.

             The page is headed by its own title — unless it draws its own
             heading: an <h1> in what it rendered, or `own_heading` (the forms
             whose sticky bar carries the name and the Save button). --}}
        @php
            $pageContent = $__env->yieldContent('content');
            $pageOwnHeading = $__env->hasSection('own_heading') || str_contains($pageContent, '<h1');
        @endphp
        <main class="p-(--admin-gutter)">
            <div class="mx-auto w-full max-w-[1600px] flex flex-col gap-6">
                @unless ($pageOwnHeading)
                  {{-- A screen with no tabs over its card puts its actions level
                       with the heading (`title_actions`) rather than in a row
                       of their own that stood empty but for a button. --}}
                  <div class="flex flex-wrap items-center justify-between gap-3">
                    <h1 class="text-2xl font-semibold leading-tight flex items-center gap-2">
                        <span>@yield('title', __('Dashboard'))</span>
                        {{-- What the screen is for (`title_hint`), a hover away beside
                             the name: the sentence a list used to open its card with,
                             so the card's first row is the search and the buttons. --}}
                        @hasSection('title_hint')
                            <x-popover hover placement="bottom" align="start" class="btn btn-ghost btn-sm btn-square text-base-content/50 font-normal"
                                       :label="__('About this screen')" panel-class="admin-hint-popover p-3 text-sm font-normal leading-snug">
                                @yield('title_hint')
                                <x-slot:trigger><x-icon name="info" size="md" /></x-slot:trigger>
                            </x-popover>
                        @endif
                    </h1>
                    @hasSection('title_actions')
                        <div class="admin-tabs-actions">@yield('title_actions')</div>
                    @endif
                  </div>
                @endunless
                {!! $pageContent !!}
            </div>
        </main>
    </div>

    <div class="drawer-side z-40">
        <label for="admin-drawer" class="drawer-overlay"></label>
        {{-- From `lg` the sidebar may be a strip of icons (the drawer's
             `data-sidebar`): the words (`admin-sidebar-label`) go, and each
             section's items open as a panel beside its icon — a popover, so
             the scrolling sidebar clips nothing. app.css, "The sidebar as a
             strip of icons"; the script below does the folding. --}}
        <aside class="admin-sidebar h-full w-64 bg-neutral text-neutral-content flex flex-col">
            <div class="admin-sidebar-brand h-16 flex items-center gap-2.5 px-5 border-b border-white/10 shrink-0">
                <div class="h-8 w-8 rounded-lg bg-primary text-primary-content flex items-center justify-center font-bold text-sm shrink-0">T</div>
                <span class="admin-sidebar-label text-lg font-bold leading-none">Torgash <span class="text-primary">CMS</span></span>
            </div>

            <nav class="torgash-sidebar-scroll flex-1 min-h-0 overflow-y-auto py-4 px-3">
                <ul class="menu menu-sm w-full gap-0.5 p-0 mb-4">
                    <li>
                        <a href="{{ route('admin.dashboard') }}" title="{{ __('Dashboard') }}"
                           class="rounded-lg {{ request()->routeIs('admin.dashboard') ? 'bg-primary text-primary-content hover:bg-primary' : 'hover:bg-white/5' }}">
                            <x-icon name="house" size="lg" /> <span class="admin-sidebar-label">{{ __('Dashboard') }}</span>
                        </a>
                    </li>
                </ul>

                {{-- Sections from App\Core\Admin\AdminMenu: core's screens and every
                     module's admin_menu, placed by `group`. Each is a <details>;
                     the one holding the current page is always open, the rest
                     remember what the admin last did with them (localStorage,
                     read before paint by the script below — never the only
                     state: without it every section simply starts closed). --}}
                <div class="flex flex-col gap-1" data-admin-sections>
                    @foreach (app(App\Core\Admin\AdminMenu::class)->sections() as $section)
                        <details class="group/section" data-section="{{ $section['key'] }}" @if ($section['active']) open data-active @endif>
                            <summary id="admin-section-{{ $section['key'] }}-summary" title="{{ $section['label'] }}"
                                     style="anchor-name: --admin-section-{{ $section['key'] }}"
                                     class="flex items-center gap-2 px-3 py-1.5 rounded-lg cursor-pointer select-none list-none
                                            text-xs font-semibold uppercase tracking-wider text-neutral-content/50 hover:bg-white/5 hover:text-neutral-content/80
                                            [&::-webkit-details-marker]:hidden">
                                <x-icon :name="$section['icon']" size="sm" />
                                <span class="flex-1 admin-sidebar-label">{{ $section['label'] }}</span>
                                <x-icon name="chevron-right" size="sm" class="admin-sidebar-label transition-transform group-open/section:rotate-90" />
                            </summary>
                            {{-- As a strip, this list is the panel (popover) beside the icon:
                                 anchored to its summary, which popover-anchor.js reads
                                 through `data-anchor`. The title is drawn only there. --}}
                            <ul id="admin-section-{{ $section['key'] }}" class="menu menu-sm w-full gap-0.5 p-0 pt-1 pb-2"
                                style="position-anchor: --admin-section-{{ $section['key'] }}" data-anchor="#admin-section-{{ $section['key'] }}-summary">
                                <li class="menu-title admin-sidebar-flyout-title text-xs">{{ $section['label'] }}</li>
                                @foreach ($section['items'] as $item)
                                    <li>
                                        <a href="{{ $item['url'] }}"
                                           class="rounded-lg {{ $item['active'] ? 'bg-primary text-primary-content hover:bg-primary' : 'hover:bg-white/5' }}">
                                            <x-icon :name="$item['icon'] ?? 'tag'" size="lg" /> {{ $item['title'] }}
                                        </a>
                                    </li>
                                @endforeach
                            </ul>
                        </details>
                    @endforeach
                </div>
                <script nonce="{{ csp_nonce() }}">
                    (() => {
                        const key = 'torgash.admin.sections';
                        let open = {};
                        try { open = JSON.parse(localStorage.getItem(key) || '{}'); } catch (e) {}
                        const drawer = document.querySelector('.drawer');
                        const wide = matchMedia('(width >= 64rem)');
                        const isStrip = () => drawer.dataset.sidebar === 'strip' && wide.matches;
                        const sections = [...document.querySelectorAll('[data-admin-sections] > details')];
                        sections.forEach((el) => {
                            if (!el.hasAttribute('data-active') && open[el.dataset.section]) el.open = true;
                            // What the strip opens and closes is not the admin's doing.
                            el.addEventListener('toggle', () => {
                                if (isStrip()) return;
                                open[el.dataset.section] = el.open;
                                try { localStorage.setItem(key, JSON.stringify(open)); } catch (e) {}
                            });
                        });

                        // The strip (PLAN §62): every section stays open so its
                        // list is rendered, and the list is a popover shown beside
                        // the icon on a tap; unfolded, the lists are the sections'
                        // own again, open as remembered.
                        // The button stands below this script, in the foot: looked up
                        // when it is needed, never now.
                        const toggleOf = () => document.querySelector('[data-sidebar-toggle]');
                        const apply = () => {
                            const strip = isStrip();
                            const toggle = toggleOf();
                            sections.forEach((el) => {
                                const list = el.querySelector(':scope > ul');
                                if (strip) {
                                    el.open = true;
                                    list.setAttribute('popover', 'auto');
                                    list.classList.add('torgash-popover', 'admin-sidebar-flyout');
                                } else {
                                    if (list.matches(':popover-open')) list.hidePopover();
                                    list.removeAttribute('popover');
                                    list.classList.remove('torgash-popover', 'admin-sidebar-flyout');
                                    el.open = el.hasAttribute('data-active') || !!open[el.dataset.section];
                                }
                            });
                            if (toggle) {
                                const label = drawer.dataset.sidebar === 'strip' ? toggle.dataset.expand : toggle.dataset.collapse;
                                toggle.title = label;
                                toggle.setAttribute('aria-label', label);
                            }
                        };
                        sections.forEach((el) => {
                            el.querySelector(':scope > summary').addEventListener('click', (event) => {
                                if (!isStrip()) return;
                                event.preventDefault();
                                el.querySelector(':scope > ul').togglePopover();
                            });
                        });
                        document.addEventListener('click', (event) => {
                            if (!event.target.closest('[data-sidebar-toggle]')) return;
                            const mode = drawer.dataset.sidebar === 'strip' ? 'full' : 'strip';
                            drawer.dataset.sidebar = mode;
                            apply();
                            fetch(@js(route('admin.preferences.sidebar')), {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Content-Type': 'application/json', 'Accept': 'application/json' },
                                body: JSON.stringify({ mode }),
                            }).catch(() => {});
                        });
                        wide.addEventListener('change', apply);
                        apply();
                        // Once more with the foot parsed, for the button's name.
                        document.addEventListener('DOMContentLoaded', apply);
                    })();
                </script>
            </nav>

            <div class="admin-sidebar-foot p-4 text-xs text-neutral-content/40 border-t border-white/10 flex items-center justify-between gap-2">
                <span class="admin-sidebar-label">v{{ config('torgash.version') }}</span>
                {{-- Fold the sidebar into a strip of icons, or open it back — from
                     `lg`, where it stands beside the page. --}}
                <button type="button" class="btn btn-ghost btn-sm btn-square text-neutral-content/60 hidden lg:inline-flex" data-sidebar-toggle
                        title="{{ __('Collapse the menu') }}" aria-label="{{ __('Collapse the menu') }}"
                        data-collapse="{{ __('Collapse the menu') }}" data-expand="{{ __('Expand the menu') }}">
                    <x-icon name="chevrons-left" class="admin-sidebar-label" />
                    <x-icon name="chevrons-right" class="admin-sidebar-strip-only" />
                </button>
            </div>
        </aside>
        {{-- The strip's panels: the arrow's side and, in a browser without
             anchor positioning, their place. Keyed like the component's push. --}}
        @pushOnce('scripts', 'popover-anchor')
            <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/popover-anchor.js') }}" defer></script>
        @endPushOnce
    </div>
</div>

{{-- Alpine components pushed by views/modules. Runs before the deferred alpine.min.js, so
     an alpine:init listener registered here is always in place when Alpine boots. --}}
@stack('scripts')
</body>
</html>
