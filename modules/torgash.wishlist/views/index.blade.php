{{--
    The wishlist page (PLAN §35.3, the owner's mock-up). Inside the profile for
    a signed-in customer (the account menu beside it); the same list, on its
    own, for a guest — whose list is kept as a basket is.

    The heading carries what the list can do beyond holding products — the
    letters and the link, each a button opening its panel (extras) — so the
    products come straight under it. The filters are the list's own toolbar,
    one GET form: its switches submit it as they change, Enter submits the
    search, and without JavaScript "Show" does it all. On a phone everything
    but the search folds behind "Filters", open when one is chosen. A short
    list is read at a glance: no toolbar under six products, unless a filter
    is on. "Clear" is a form of its own at the foot of the list, never inside
    the filter form.
--}}
@extends('layouts.storefront')

@section('content')
    <x-breadcrumbs />
    @php
        $wishFiltered = $filters['category'] > 0 || $filters['q'] !== '' || $filters['in_stock'] || $filters['on_sale'];
        $wishFilterCount = ($filters['category'] > 0 ? 1 : 0) + ($filters['in_stock'] ? 1 : 0) + ($filters['on_sale'] ? 1 : 0);
        $wishToolbar = $total > 5 || $wishFiltered;
    @endphp

    <div class="flex flex-col md:flex-row gap-4 md:gap-6">
        @auth
            @include('account._nav')
        @endauth

        <div class="flex-1 min-w-0 flex flex-col gap-4">
            <div class="flex items-center gap-2 min-h-9">
                {{-- The size of every account page's heading inside the
                     profile; a guest's page has no column beside it. --}}
                <h1 @class(['font-bold flex-1 min-w-0', 'text-xl' => auth()->check(), 'text-2xl' => ! auth()->check()])>{{ __('Wishlist') }}</h1>
                @if ($total > 0)
                    @include('torgash.wishlist::extras', ['wishList' => $list])
                @endif
            </div>
            @if (session('status'))
                <div class="alert alert-success">{{ session('status') }}</div>
            @endif

            @if ($total === 0)
                <div class="card bg-base-100 border border-base-300/60 shadow-sm">
                    <div class="card-body items-center text-center gap-3 py-12">
                        <x-icon name="heart" size="xl" class="opacity-30" />
                        <p class="opacity-70">{{ __('Nothing here yet. Press the heart on a product to keep it here.') }}</p>
                        <a href="{{ route('catalog.index') }}" class="btn btn-primary btn-sm">{{ __('Browse the catalogue') }}</a>
                    </div>
                </div>
            @else
                <div class="card bg-base-100 border border-base-300/60 shadow-sm">
                    @if ($wishToolbar)
                        {{-- Emptied fields are disabled on the way out, so the
                             address carries only what is chosen. --}}
                        <form method="GET" action="{{ route('wishlist.index') }}" id="wishlist-filters" class="flex flex-col gap-3 p-3 md:p-4 border-b border-base-300"
                              x-data="{ open: @js($wishFilterCount > 0) }"
                              x-on:submit="$el.querySelectorAll('input[type=search], select').forEach(f => { if (f.value === '') f.disabled = true })">
                            <div class="flex items-center gap-2">
                                <label class="input flex-1 min-w-0">
                                    <x-icon name="search" size="sm" class="opacity-50" />
                                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" enterkeyhint="search" placeholder="{{ __('Search by SKU or name') }}">
                                </label>
                                <button type="button" class="torgash-js-only md:hidden btn gap-2 shrink-0" x-on:click="open = ! open"
                                        x-bind:aria-expanded="open" aria-expanded="{{ $wishFilterCount > 0 ? 'true' : 'false' }}" aria-controls="wishlist-filter-fold">
                                    <x-icon name="sliders-horizontal" size="sm" />
                                    {{ __('Filters') }}
                                    @if ($wishFilterCount > 0)
                                        <span class="badge badge-primary badge-sm">{{ $wishFilterCount }}</span>
                                    @endif
                                </button>
                                <noscript><button class="btn btn-outline">{{ __('Show') }}</button></noscript>
                            </div>
                            <div id="wishlist-filter-fold" @class(['torgash-fold flex flex-col md:flex-row md:flex-wrap md:items-center gap-3 md:gap-5', 'is-open' => $wishFilterCount > 0])
                                 x-bind:class="open && 'is-open'">
                                @if (count($categoryOptions) > 1 || $filters['category'] > 0)
                                    <select name="category" class="select w-full md:w-64" x-on:change="$el.form.requestSubmit()" aria-label="{{ __('Category') }}">
                                        <option value="">{{ __('All categories') }}</option>
                                        @foreach ($categoryOptions as $wishCategoryId => $wishCategoryName)
                                            <option value="{{ $wishCategoryId }}" @selected($filters['category'] === (int) $wishCategoryId)>{{ $wishCategoryName }}</option>
                                        @endforeach
                                    </select>
                                @endif
                                <label class="label cursor-pointer gap-3">
                                    <input type="checkbox" name="in_stock" value="1" class="toggle toggle-primary" @checked($filters['in_stock']) x-on:change="$el.form.requestSubmit()">
                                    <span class="text-sm text-base-content">{{ __('In stock only') }}</span>
                                </label>
                                <label class="label cursor-pointer gap-3">
                                    <input type="checkbox" name="on_sale" value="1" class="toggle toggle-primary" @checked($filters['on_sale']) x-on:change="$el.form.requestSubmit()">
                                    <span class="text-sm text-base-content">{{ __('On sale only') }}</span>
                                </label>
                            </div>
                        </form>
                    @endif

                    @if ($products->isEmpty())
                        <div class="card-body text-center opacity-70 py-10">{{ __('Nothing in the wishlist matches these filters.') }}</div>
                    @else
                        @php $wishShown = app(\Torgash\Catalog\Pricing\ShownPrice::class); @endphp
                        <ul class="divide-y divide-base-300">
                            @foreach ($products as $wishRow)
                                @include('torgash.wishlist::row', ['wishRow' => $wishRow, 'wishShown' => $wishShown, 'wishPaths' => $paths, 'wishEditable' => true])
                            @endforeach
                        </ul>
                    @endif

                    {{-- Rare and final: at the foot of the list, out of the
                         way of the controls one reaches for every day. --}}
                    <form method="POST" action="{{ route('wishlist.clear') }}" data-confirm="{{ __('Remove everything from the wishlist?') }}" class="border-t border-base-300 p-2">
                        @csrf
                        <button class="btn btn-sm btn-ghost text-error gap-2"><x-icon name="trash-2" size="sm" /> {{ __('Clear the wishlist') }}</button>
                    </form>
                </div>

                @if ($products->hasPages())
                    <div>{{ $products->links() }}</div>
                @endif
            @endif
        </div>
    </div>
@endsection
