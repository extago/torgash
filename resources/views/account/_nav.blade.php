{{-- The account's side column: who is signed in, then the places in GROUPS
     (purchases, discounts and bonuses, profile and security) under small
     headings in one card, and "Logout" apart — the column keeps to its own height, never
     stretched down to the length of the page. Not sticky: with its groups it
     is taller than a laptop's screen, and a sticky column that tall hides its
     own last items.

     One list of links, drawn two ways by CSS: on a phone the same card is a
     menu under ONE full-width button — who is signed in, the column's own
     first block (a strip of ten pills swiped sideways hid most of them, and
     stacked they pushed the page below the fold); from md up it is the
     column, simply in the page — app.css, .torgash-account-places. The
     page's own heading says where you are, so the button names the person,
     not the place. The menu hangs from its button, so it closes when the
     page scrolls rather than riding up over the sticky header. --}}
@php
    $accountUser = auth()->user();
    // Whatever a module puts in the account area. A LIST of items, not a
    // string of markup: the item's look and the active state stay core's,
    // so a module adding "my enquiries" cannot drift away from how the
    // other items look. Each item is
    // ['label' => …, 'url' => …, 'icon' => …, 'active' => bool], plus an
    // optional 'badge' — a count worth interrupting somebody for, drawn the
    // same way whoever supplies it — and 'group': shopping | bonuses |
    // account (anything else goes in a card with no heading, last).
    $accountLinks = [
        ['label' => __('Orders'), 'url' => route('account.orders'), 'icon' => 'shopping-bag', 'active' => request()->routeIs('*account.orders'), 'group' => 'shopping'],
        ['label' => __('Profile'), 'url' => route('account.dashboard'), 'icon' => 'user', 'active' => request()->routeIs('*account.dashboard'), 'group' => 'account'],
        ['label' => __('Addresses'), 'url' => route('account.addresses.index'), 'icon' => 'map-pin', 'active' => request()->routeIs('*account.addresses.*'), 'group' => 'account'],
        ...hook_filter('core.account.nav', []),
    ];
    $accountGroupTitles = ['shopping' => __('Purchases'), 'bonuses' => __('Discounts and bonuses'), 'account' => __('Profile and security')];
    $accountGroups = collect($accountLinks)
        ->groupBy(fn ($item) => array_key_exists($item['group'] ?? '', $accountGroupTitles) ? $item['group'] : '')
        ->sortBy(fn ($items, $group) => $group === '' ? 99 : array_search($group, array_keys($accountGroupTitles), true));
    // Two letters, as the shop's staff and its customers sign: "Bogdan Shylkin" → БШ.
    $accountInitials = $accountUser
        ? (mb_strtoupper(collect(preg_split('/\s+/u', trim((string) $accountUser->name)) ?: [])->filter()->take(2)->map(fn ($word) => mb_substr($word, 0, 1))->implode('')) ?: '?')
        : '';
    $accountRow = 'flex items-center gap-2.5 whitespace-nowrap rounded-field px-2.5 h-9 text-sm transition-colors';
@endphp
<aside class="torgash-account-nav shrink-0 md:w-60 md:self-start">
    <button type="button" popovertarget="account-places" style="anchor-name: --account-places"
            class="md:hidden w-full flex items-center gap-3 bg-base-100 border border-base-300/60 rounded-box shadow-sm px-3 py-2 text-left cursor-pointer">
        <span class="w-9 h-9 rounded-full bg-primary text-primary-content grid place-items-center text-sm font-semibold shrink-0" aria-hidden="true">
            @if ($accountUser) {{ $accountInitials }} @else <x-icon name="user" size="sm" /> @endif
        </span>
        <span class="flex-1 min-w-0 leading-tight">
            <span class="block text-sm font-semibold truncate">{{ $accountUser?->name ?: __('My account') }}</span>
            @if ($accountUser?->email || $accountUser?->phone)
                <span class="block text-xs opacity-60 truncate">{{ $accountUser->email ?: \App\Core\Support\Phones::display($accountUser->phone) }}</span>
            @endif
        </span>
        <x-icon name="chevron-down" class="torgash-account-chevron opacity-60 shrink-0" />
    </button>
    {{-- ONE card: who, then the groups under small headings split by a
         hairline — separate cards with big rows made the column the
         heaviest thing on the page. On a phone it scrolls inside itself
         when it is taller than the screen under its button. --}}
    <nav id="account-places" popover style="position-anchor: --account-places" data-align="start" aria-label="{{ __('My account') }}"
         x-data x-on:scroll.window.passive="$el.matches(':popover-open') && $el.hidePopover()"
         class="torgash-popover torgash-account-places bg-base-100 text-base-content border border-base-300 md:border-base-300/60 rounded-box shadow-lg md:shadow-sm p-2">
        <div class="max-h-[calc(100dvh-10rem)] overflow-y-auto overscroll-contain md:max-h-none md:overflow-visible">
            {{-- On a phone this is the button itself. --}}
            @if ($accountUser)
                <div class="hidden md:flex items-center gap-2.5 px-2 pt-1.5 pb-3">
                    <span class="w-9 h-9 rounded-full bg-primary text-primary-content grid place-items-center text-sm font-semibold shrink-0" aria-hidden="true">{{ $accountInitials }}</span>
                    <div class="min-w-0 leading-tight">
                        <div class="text-sm font-semibold truncate">{{ $accountUser->name }}</div>
                        @if ($accountUser->email || $accountUser->phone)
                            <div class="text-xs opacity-60 truncate">{{ $accountUser->email ?: \App\Core\Support\Phones::display($accountUser->phone) }}</div>
                        @endif
                    </div>
                </div>
            @endif

            @foreach ($accountGroups as $accountGroup => $accountItems)
                <section class="border-t border-base-300/60 pt-2 pb-1 max-md:first-of-type:border-t-0 max-md:first-of-type:pt-0">
                    @if ($accountGroup !== '')
                        <h2 class="text-xs font-medium opacity-50 px-2.5 pb-1">{{ $accountGroupTitles[$accountGroup] }}</h2>
                    @endif
                    <ul class="flex flex-col">
                        @foreach ($accountItems as $item)
                            <li>
                                <a href="{{ $item['url'] }}" @if (! empty($item['active'])) aria-current="page" @endif
                                   @class([
                                       $accountRow,
                                       'bg-base-200 font-semibold' => ! empty($item['active']),
                                       'hover:bg-base-200' => empty($item['active']),
                                   ])>
                                    <x-icon :name="$item['icon'] ?? 'circle'" size="sm" class="shrink-0 {{ ! empty($item['active']) ? 'text-primary' : 'opacity-70' }}" />
                                    <span class="flex-1 truncate">{{ $item['label'] }}</span>
                                    @if (! empty($item['badge']))
                                        <span class="badge badge-primary badge-sm">{{ $item['badge'] }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach

            <form method="POST" action="{{ route('account.logout') }}" class="border-t border-base-300/60 pt-1">
                @csrf
                <button type="submit" class="{{ $accountRow }} w-full hover:bg-base-200 cursor-pointer text-left">
                    <x-icon name="log-out" size="sm" class="shrink-0 opacity-70" />
                    <span class="flex-1">{{ __('Logout') }}</span>
                </button>
            </form>
        </div>
    </nav>
</aside>

{{-- Keyed as the popover component keys it: one tag a page, placing the panel where
     the browser cannot (iOS 18 and older). --}}
@pushOnce('scripts', 'popover-anchor')
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/popover-anchor.js') }}" defer></script>
@endPushOnce
