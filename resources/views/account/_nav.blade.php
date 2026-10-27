{{-- The account's side column: who is signed in, then the places in GROUPS
     (purchases, discounts and bonuses, profile and security) under small
     headings in one card, and "Logout" apart — the column keeps to its own height, never
     stretched down to the length of the page. Not sticky: with its groups it
     is taller than a laptop's screen, and a sticky column that tall hides its
     own last items.

     One list of links, drawn two ways by CSS: on a phone the cards dissolve
     (`contents`) and their links become one strip of pills that scrolls
     sideways — ten items stacked would push the page itself below the fold. --}}
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
    $accountRow = 'flex items-center gap-2.5 whitespace-nowrap rounded-full md:rounded-field px-3.5 md:px-2.5 h-9 text-sm transition-colors';
@endphp
<aside class="torgash-account-nav shrink-0 md:w-60 md:self-start">
    {{-- On a phone the strip opens scrolled to the page you are on. --}}
    <nav aria-label="{{ __('My account') }}" x-data x-init="const a = $el.querySelector('[aria-current]'); if (a && $el.scrollWidth > $el.clientWidth) $el.scrollLeft = a.offsetLeft - 16"
         class="relative -mx-3 px-3 sm:-mx-4 sm:px-4 md:mx-0 md:px-0 overflow-x-auto md:overflow-visible [scrollbar-width:none]">
        {{-- ONE card on a computer: who, then the groups under small headings
             split by a hairline — separate cards with big rows made the
             column the heaviest thing on the page. --}}
        <div class="flex md:flex-col gap-1.5 md:gap-0 w-max md:w-full md:bg-base-100 md:rounded-box md:border md:border-base-300/60 md:shadow-sm md:p-2">
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
                <section class="contents md:block md:border-t md:border-base-300/60 md:pt-2 md:pb-1">
                    @if ($accountGroup !== '')
                        <h2 class="hidden md:block text-xs font-medium opacity-50 px-2.5 pb-1">{{ $accountGroupTitles[$accountGroup] }}</h2>
                    @endif
                    <ul class="contents md:flex md:flex-col">
                        @foreach ($accountItems as $item)
                            <li>
                                <a href="{{ $item['url'] }}" @if (! empty($item['active'])) aria-current="page" @endif
                                   @class([
                                       $accountRow,
                                       'bg-neutral text-neutral-content md:bg-base-200 md:text-base-content font-semibold' => ! empty($item['active']),
                                       'bg-base-100 border border-base-300/70 md:border-0 md:bg-transparent hover:bg-base-200' => empty($item['active']),
                                   ])>
                                    <x-icon :name="$item['icon'] ?? 'circle'" size="sm" class="shrink-0 {{ ! empty($item['active']) ? 'md:text-primary' : 'opacity-70' }}" />
                                    <span class="md:flex-1 md:truncate">{{ $item['label'] }}</span>
                                    @if (! empty($item['badge']))
                                        <span class="badge badge-primary badge-sm">{{ $item['badge'] }}</span>
                                    @endif
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach

            <form method="POST" action="{{ route('account.logout') }}" class="contents md:block md:border-t md:border-base-300/60 md:pt-1">
                @csrf
                <button type="submit" class="{{ $accountRow }} w-full bg-base-100 border border-base-300/70 md:border-0 md:bg-transparent hover:bg-base-200 cursor-pointer text-left">
                    <x-icon name="log-out" size="sm" class="shrink-0 opacity-70" />
                    <span class="md:flex-1">{{ __('Logout') }}</span>
                </button>
            </form>
        </div>
    </nav>
</aside>
