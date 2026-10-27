{{--
    The phone menu (PLAN §38): a drawer from the left whose whole content is
    the `storefront.mobile.menu` zone — blocks the shop moves, drops and
    restyles (account, town, categories, links, language, contacts come laid
    out by the theme). Opened by anything dispatching `torgash-drawer` (the
    header's menu button, the tab bar's "Catalogue"); Escape, the backdrop and
    the close button shut it. The page behind does not scroll while it is open.
--}}
<div x-data="{ open: false }"
     x-on:torgash-drawer.window="open = $event.detail ?? ! open"
     x-on:keydown.escape.window="open = false"
     x-effect="document.documentElement.classList.toggle('overflow-hidden', open)"
     class="lg:hidden">
    <div x-show="open" x-cloak x-transition.opacity x-on:click="open = false"
         class="fixed inset-0 z-50 bg-black/45" aria-hidden="true"></div>
    <aside x-show="open" x-cloak
           x-transition:enter="transition ease-out duration-250" x-transition:enter-start="-translate-x-full" x-transition:enter-end="translate-x-0"
           x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-x-0" x-transition:leave-end="-translate-x-full"
           class="fixed inset-y-0 left-0 z-50 w-[88vw] max-w-sm bg-base-100 text-base-content shadow-2xl flex flex-col"
           role="dialog" aria-modal="true" aria-label="{{ __('Menu') }}">
        <div class="flex items-center justify-between gap-3 px-5 h-14 border-b border-base-300 shrink-0">
            <a href="{{ route('catalog.home') }}" class="text-xl font-bold truncate"><x-theme.logo :on-dark="false" /></a>
            <div class="flex items-center gap-1 shrink-0">
                {{-- The phone's only way to the light / dark switch: the
                     header's strip that carries it is a desktop row. --}}
                @include('layouts.partials.mode-switch', ['modeSwitchClass' => 'btn-touch'])
                <button type="button" class="btn btn-ghost btn-circle btn-touch" x-on:click="open = false" aria-label="{{ __('Close') }}"><x-icon name="x" size="lg" /></button>
            </div>
        </div>
        <div class="overflow-y-auto overscroll-contain grow flex flex-col [&>*]:shrink-0 divide-y divide-base-300 pb-[env(safe-area-inset-bottom)]">
            {!! zone('storefront.mobile.menu') !!}
        </div>
    </aside>
</div>
