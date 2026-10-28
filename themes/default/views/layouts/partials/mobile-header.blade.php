{{--
    The phone's header (PLAN §38): one row, sticky, below `lg` only — the
    desktop headers stand down there. Three shapes, the theme's "mobile_header":

    - `search` (the owner's first mock-up): the menu and the search field —
      the whole row is for finding things; the shop's name lives in the
      drawer and "Home" in the tab bar below.
    - `search_mark`: the same, with the shop's sign inside the field on the
      left — the shop is named without giving up the field.
    - `logo`: the menu, the logo and icons. The magnifier unfolds the search
      over the whole row and a cross folds it back; on a search page it
      starts unfolded, holding what was asked. No basket here: the tab bar
      has it with its count, and a second count would be a second #cart-badge.
      The phone icon opens a sheet from the bottom — the blocks of the
      `storefront.mobile.contacts` zone, laid out for a thumb — and dials
      straight away while that zone is empty (or with no script).
--}}
@php
    $mobileHeader = in_array(theme_setting('mobile_header', 'search'), ['search', 'search_mark', 'logo'], true)
        ? theme_setting('mobile_header', 'search') : 'search';
    $mobileSearchUrl = \Illuminate\Support\Facades\Route::has('catalog.search') ? route('catalog.search') : '#';
    $mobileMark = $mobileHeader === 'search_mark' ? theme_image('mobile_mark') : null;
    $mobileShopName = current_storefront()?->name ?? 'Torgash';
    $mobilePhone = $mobileHeader === 'logo' && theme_setting('mobile_header_phone', true)
        ? app(\App\Core\Content\ContactPlaceholders::class)->phone() : '';
    $mobileContacts = $mobilePhone !== '' ? trim(zone('storefront.mobile.contacts', ['storefront' => $storefront])) : '';
@endphp
<header class="torgash-bar torgash-header-bar lg:hidden bg-neutral text-neutral-content sticky top-0 z-30 shadow-md"
        @if ($mobileHeader === 'logo') x-data="{ searching: @js(filled(request('q'))) }" @endif>
    <div class="flex items-center gap-2 px-(--torgash-gutter) h-14">
        <button type="button" class="torgash-js-only btn btn-ghost btn-square btn-touch shrink-0" x-data x-on:click="$dispatch('torgash-drawer', true)" aria-label="{{ __('Menu') }}">
            <x-icon name="menu" size="xl" />
        </button>

        @if ($mobileHeader === 'logo')
            <a href="{{ route('catalog.home') }}" class="grow min-w-0 flex items-center text-lg font-bold truncate" x-show="! searching">
                <x-theme.logo :on-dark="true" height="h-7" />
            </a>
            <div class="flex items-center gap-2 shrink-0" x-show="! searching">
                {{-- A link underneath: with no script it opens the search page. --}}
                <a href="{{ $mobileSearchUrl }}" class="torgash-mobile-icon btn btn-square" aria-label="{{ __('Search') }}"
                   x-on:click.prevent="searching = true; $nextTick(() => $refs.mobileQuery.focus())">
                    <x-icon name="search" size="lg" />
                </a>
                @if ($mobilePhone !== '')
                    <a href="{{ app(\App\Core\Content\ContactPlaceholders::class)->phoneLink($mobilePhone) }}" class="torgash-mobile-icon btn btn-square" aria-label="{{ __('Call us') }}"
                       @if ($mobileContacts !== '') x-on:click.prevent="document.getElementById('torgash-contacts-sheet').showModal()" @endif>
                        <x-icon name="phone" size="lg" />
                    </a>
                @endif
            </div>
        @endif

        {{-- In the `logo` shape the field waits for the magnifier (x-cloak:
             without a script the magnifier is a plain link to the search page). --}}
        <form method="GET" action="{{ $mobileSearchUrl }}" role="search" data-live-search class="relative grow min-w-0"
              @if ($mobileHeader === 'logo') x-cloak x-show="searching" x-on:keydown.escape="searching = false" @endif>
            @if ($mobileHeader === 'search_mark')
                <a href="{{ route('catalog.home') }}" class="torgash-search-mark" aria-label="{{ $mobileShopName }}">
                    @if ($mobileMark)
                        <img src="{{ $mobileMark }}" alt="">
                    @else
                        <span aria-hidden="true">{{ mb_strtoupper(mb_substr($mobileShopName, 0, 1)) }}</span>
                    @endif
                </a>
            @endif
            <input type="search" name="q" value="{{ request('q') }}" placeholder="{{ __('Search products') }}" aria-label="{{ __('Search products') }}" x-ref="mobileQuery"
                   @class(['input w-full rounded-field bg-white/10 border-white/15 pr-11 placeholder:text-neutral-content/50 focus:border-primary', 'pl-12' => $mobileHeader === 'search_mark'])>
            <button type="submit" aria-label="{{ __('Search') }}" class="absolute right-1 top-1/2 -translate-y-1/2 btn btn-ghost btn-sm btn-touch btn-square">
                <x-icon name="search" size="lg" />
            </button>
            {!! hook_render('storefront.search.controls', ['storefront' => $storefront]) !!}
        </form>

        @if ($mobileHeader === 'logo')
            <button type="button" x-cloak x-show="searching" x-on:click="searching = false" class="btn btn-ghost btn-square btn-touch shrink-0" aria-label="{{ __('Close the search') }}">
                <x-icon name="x" size="lg" />
            </button>
        @endif
    </div>
</header>

@if ($mobileContacts !== '')
    {{-- Drawn once (the phone header is), so a fixed id is safe. The same
         sheet as "your town": from the bottom, backdrop and × close it. --}}
    <dialog id="torgash-contacts-sheet" class="modal modal-bottom lg:hidden" aria-labelledby="torgash-contacts-title" x-data>
        <div class="modal-box p-0 max-h-[85dvh] flex flex-col">
            <div class="flex items-center gap-2.5 px-5 py-3 border-b border-base-300 shrink-0">
                <x-icon name="phone" size="lg" class="text-primary shrink-0" />
                <h3 id="torgash-contacts-title" class="font-semibold leading-tight">{{ __('Contacts') }}</h3>
                <button type="button" class="btn btn-ghost btn-sm btn-circle ms-auto shrink-0" x-on:click="$root.close()" aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
            </div>
            <div class="overflow-y-auto min-h-0 px-5 py-4 flex flex-col gap-4">{!! $mobileContacts !!}</div>
        </div>
        <button type="button" class="modal-backdrop" tabindex="-1" aria-hidden="true" x-on:click="$root.close()"></button>
    </dialog>
@endif
