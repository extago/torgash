{{-- The account menu of the dark header: who is signed in, the few places
     somebody goes from ANY page, and the rest of the account one click
     further — not the whole sidebar of the account area poured into a
     dropdown.

     The items are the sidebar's own (core's plus `core.account.nav`; the
     profile is the name plate on top, so it is not a row as well),
     so a module registers once and is reachable from both. What is "quick":
     an item that says `'quick' => true` (the orders, the wishlist), and any
     item with a badge — a number worth interrupting somebody for has earned
     its row. Everything else waits behind "All sections" when the theme's
     `account_menu` is `compact`; `full` lists all of it, as before.

     A popover (core's popover component), not a daisyUI dropdown: CLAUDE.md. --}}
@php
    $accountUser = Auth::user();
    $accountItems = collect([
        ['label' => __('Addresses'), 'url' => route('account.addresses.index'), 'icon' => 'map-pin'],
        ['label' => __('Orders'), 'url' => route('account.orders'), 'icon' => 'shopping-bag', 'quick' => true],
        ...hook_filter('core.account.nav', []),
    ])->filter(fn ($item) => is_array($item) && ! empty($item['url']));
    $accountCompact = theme_setting('account_menu', 'compact') !== 'full';
    $accountIsQuick = fn ($item) => ! empty($item['quick']) || ! empty($item['badge']);
    $accountQuick = $accountCompact ? $accountItems->filter($accountIsQuick)->values() : $accountItems->values();
    $accountRest = $accountCompact ? $accountItems->reject($accountIsQuick)->values() : collect();
    $accountInitials = \Illuminate\Support\Str::of($accountUser->name ?: $accountUser->email)
        ->explode(' ')->filter()->take(2)->map(fn ($word) => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
@endphp
{{-- `$accountMenuStacked` (the three-row header): the icon over a word, like
     the counters beside it (.torgash-header-icons), instead of icon + name. --}}
<x-popover id="account-menu" :class="($accountMenuStacked ?? false) ? 'btn btn-ghost normal-case shrink-0' : 'btn btn-ghost btn-sm gap-1.5 normal-case shrink-0'" panel-class="w-72 p-0" :label="__('My account')">
    {{-- Who: the whole plate is the way into the account. --}}
    <a href="{{ route('account.dashboard') }}" class="flex items-center gap-3 px-4 py-3 border-b border-base-300 hover:bg-base-200 rounded-t-box">
        <span class="size-10 shrink-0 rounded-full bg-primary/15 text-primary font-semibold flex items-center justify-center">{{ $accountInitials }}</span>
        <span class="min-w-0 grow">
            <span class="block font-semibold truncate">{{ $accountUser->name }}</span>
            <span class="block text-xs opacity-60 truncate">{{ $accountUser->email ?: $accountUser->phone }}</span>
        </span>
        <x-icon name="chevron-right" size="sm" class="opacity-40 shrink-0" />
    </a>

    <div class="p-2" x-data="{ more: false }">
        <ul class="menu w-full p-0 gap-0.5">
            @foreach ($accountQuick as $accountItem)
                @include('layouts.partials.account-menu-item', ['accountItem' => $accountItem])
            @endforeach
        </ul>

        @if ($accountRest->isNotEmpty())
            <ul class="menu w-full p-0 gap-0.5" x-show="more" x-cloak>
                @foreach ($accountRest as $accountItem)
                    @include('layouts.partials.account-menu-item', ['accountItem' => $accountItem])
                @endforeach
            </ul>
            <ul class="menu w-full p-0">
                <li>
                    <button type="button" class="flex items-center gap-2.5 py-2 opacity-70 hover:opacity-100" x-on:click="more = ! more" x-bind:aria-expanded="more">
                        <x-icon name="chevron-down" size="sm" class="shrink-0 transition-transform" x-bind:class="more && 'rotate-180'" />
                        <span x-text="more ? @js(__('Fewer sections')) : @js(__('All sections'))">{{ __('All sections') }}</span>
                    </button>
                </li>
            </ul>
        @endif
    </div>

    <ul class="menu w-full p-2 gap-0.5 border-t border-base-300">
        {{-- Staff reach their own screens from the shop they are looking at. --}}
        @if ($accountUser->roles->isNotEmpty() && \Illuminate\Support\Facades\Route::has('admin.dashboard'))
            <li>
                <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2.5 py-2">
                    <x-icon name="layout-dashboard" size="sm" class="opacity-60 shrink-0" /> {{ __('Control panel') }}
                </a>
            </li>
        @endif
        <li>
            {{-- The form is the menu item; `p-0 grid-cols-1` so the whole row
                 takes the click, not only the word (the account sidebar's trick). --}}
            <form method="POST" action="{{ route('account.logout') }}" class="p-0 grid-cols-1">
                @csrf
                <button type="submit" class="flex items-center gap-2.5 w-full px-3 py-2 text-error">
                    <x-icon name="log-out" size="sm" class="shrink-0" /> {{ __('Logout') }}
                </button>
            </form>
        </li>
    </ul>

    <x-slot:trigger>
        @if ($accountMenuStacked ?? false)
            <x-icon name="user" size="lg" />
            <span class="torgash-header-icon-word max-w-20 truncate">{{ \Illuminate\Support\Str::before(trim($accountUser->name), ' ') ?: __('My account') }}</span>
        @else
            <x-icon name="user" />
            <span class="hidden lg:inline max-w-32 truncate">{{ $accountUser->name }}</span>
            <x-icon name="chevron-down" size="sm" class="opacity-50" />
        @endif
    </x-slot:trigger>
</x-popover>
