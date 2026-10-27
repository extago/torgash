{{--
    The phone's tab bar (PLAN §38): five places a thumb reaches. The theme's
    own three — Home, Catalogue (opens the menu), Profile — and whatever
    modules add through `storefront.tabbar`, a LIST of
    ['key', 'label', 'icon', 'url', 'badge', 'order'] (the wishlist, the
    basket), sorted by `order` and cut at five. The current page's tab is
    marked. Below `lg` only; `pb-safe` keeps it clear of a phone's home bar.
--}}
@php
    // The shop's own bar (PLAN §39.6): the menu chosen in the theme setting
    // "The bottom bar shows", whose entries are shortcuts (the basket with its
    // count, "open the catalogue"), pages and categories — the first five with
    // somewhere to go. Nothing chosen, or no menu module: what the modules
    // registered, as before — except entries marked 'auto' => false, which are
    // offered to menus but do not push the basket out of five places.
    $tabbarCode = (string) theme_setting('tabbar_menu', 'tabbar');
    $tabbarBuilder = $tabbarCode !== '' && class_exists(\Torgash\Menus\MenuBuilder::class) ? app(\Torgash\Menus\MenuBuilder::class) : null;
    $tabbarItems = $tabbarBuilder && $tabbarBuilder->exists($tabbarCode)
        ? collect($tabbarBuilder->nodes($tabbarCode))
            ->filter(fn ($node) => $node->href !== null || $node->opensDrawer())
            ->take(5)
            ->map(fn ($node) => [
                'key' => $node->key ?? $node->id,
                'label' => $node->label,
                'icon' => $node->icon ?? 'circle',
                'url' => $node->href,
                'drawer' => $node->opensDrawer(),
                'badge' => $node->counter ?: null,
                'active' => $node->active || $node->trail,
            ])
            ->values()
        : null;

    $tabbarItems ??= collect(hook_filter('storefront.tabbar', [
        ['key' => 'home', 'label' => __('Home'), 'icon' => 'house', 'url' => route('catalog.home'), 'order' => 10, 'active' => request()->routeIs('*catalog.home')],
        ['key' => 'catalog', 'label' => __('Catalog'), 'icon' => 'layout-grid', 'drawer' => true, 'order' => 20, 'active' => request()->routeIs('*catalog.index', '*catalog.category')],
        ['key' => 'account', 'label' => __('Profile'), 'icon' => 'user-round', 'url' => auth()->check() ? route('account.dashboard') : route('account.login'), 'order' => 80, 'active' => request()->routeIs('*account.*') || (auth()->check() && request()->routeIs('*checkout.confirmation'))],
    ], ['storefront' => $storefront]))->filter(fn ($tab) => ($tab['auto'] ?? true) !== false)->sortBy('order')->take(5)->values();
@endphp
<nav class="lg:hidden fixed bottom-0 inset-x-0 z-40 bg-base-100 border-t border-base-300 pb-[env(safe-area-inset-bottom)]" aria-label="{{ __('Main sections') }}">
    <ul class="grid h-16" style="grid-template-columns: repeat({{ $tabbarItems->count() }}, minmax(0, 1fr))">
        @foreach ($tabbarItems as $tabbarItem)
            @php $tabbarActive = (bool) ($tabbarItem['active'] ?? false); @endphp
            <li>
                @if (! empty($tabbarItem['drawer']))
                    {{-- A link that a script turns into "open the menu": without
                         one it is still the way to the catalogue. --}}
                    <a href="{{ $tabbarItem['url'] ?? route('catalog.index') }}" x-data x-on:click.prevent="$dispatch('torgash-drawer', true)"
                       @class(['w-full h-full flex flex-col items-center justify-center gap-1 text-xs', 'text-primary' => $tabbarActive, 'text-base-content/70' => ! $tabbarActive])>
                        <x-icon :name="$tabbarItem['icon']" size="xl" />
                        <span>{{ $tabbarItem['label'] }}</span>
                    </a>
                @else
                    <a href="{{ $tabbarItem['url'] }}" @if ($tabbarActive) aria-current="page" @endif
                       @class(['w-full h-full flex flex-col items-center justify-center gap-1 text-xs', 'text-primary' => $tabbarActive, 'text-base-content/70' => ! $tabbarActive])>
                        <span class="relative">
                            <x-icon :name="$tabbarItem['icon']" size="xl" />
                            {{-- Always there, empty at zero, with a stable id: a module
                                 answering an add refreshes it out of band. --}}
                            <span id="tabbar-badge-{{ $tabbarItem['key'] }}" @class(['badge badge-primary torgash-tabbar-badge', 'hidden' => empty($tabbarItem['badge'])])>{{ $tabbarItem['badge'] ?? '' }}</span>
                        </span>
                        <span>{{ $tabbarItem['label'] }}</span>
                    </a>
                @endif
            </li>
        @endforeach
    </ul>
</nav>
