{{--
    The wishlist page (PLAN §35.3, the owner's mock-up). Inside the profile for
    a signed-in customer (the account menu beside it); the same list, on its
    own, for a guest — whose list is kept as a basket is.

    The filters are one GET form: its switches submit it as they change, and
    "Show" does the same without JavaScript. "Clear" is a form of its own,
    BESIDE the filter form, never inside it.
--}}
@extends('layouts.storefront')

@section('content')
    <x-breadcrumbs />
    {{-- For a guest the heading tops the page; inside the profile it heads
         the column beside the account menu, as on every account page. --}}
    @guest
        <h1 class="text-2xl font-bold mb-4">{{ __('Wishlist') }}</h1>
    @endguest

    <div class="flex flex-col md:flex-row gap-6">
        @auth
            @include('account._nav')
        @endauth

        <div class="flex-1 min-w-0 flex flex-col gap-4">
            @auth
                <h1 class="text-xl font-bold">{{ __('Wishlist') }}</h1>
            @endauth
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
                    <div class="card-body gap-4">
                        <div class="flex flex-wrap items-start gap-3">
                            <form method="GET" action="{{ route('wishlist.index') }}" id="wishlist-filters" class="flex flex-wrap items-center gap-3 flex-1 min-w-0" x-data>
                                <select name="category" class="select select-bordered w-full sm:w-64" x-on:change="$el.form.requestSubmit()" aria-label="{{ __('Category') }}">
                                    <option value="">{{ __('All categories') }}</option>
                                    @foreach ($categoryOptions as $wishCategoryId => $wishCategoryName)
                                        <option value="{{ $wishCategoryId }}" @selected($filters['category'] === (int) $wishCategoryId)>{{ $wishCategoryName }}</option>
                                    @endforeach
                                </select>
                                <label class="input input-bordered flex items-center gap-2 flex-1 min-w-48">
                                    <x-icon name="search" size="sm" class="opacity-50" />
                                    <input type="search" name="q" value="{{ $filters['q'] }}" class="grow" placeholder="{{ __('Search by SKU or name') }}">
                                </label>
                                {{-- The search's own submit, the size of the field beside it:
                                     everything else on this form submits itself on change. --}}
                                <button class="btn btn-outline">{{ __('Show') }}</button>
                                <div class="flex flex-wrap items-center gap-5 w-full">
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
                            <form method="POST" action="{{ route('wishlist.clear') }}" data-confirm="{{ __('Remove everything from the wishlist?') }}">
                                @csrf
                                {{-- Field height, beside the fields it shares a row with. --}}
                                <button class="btn btn-error btn-soft gap-2"><x-icon name="trash-2" /> {{ __('Clear the wishlist') }}</button>
                            </form>
                        </div>
                    </div>
                </div>

                @include('torgash.wishlist::extras', ['wishList' => $list])

                <div class="card bg-base-100 border border-base-300/60 shadow-sm">
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
                </div>

                @if ($products->hasPages())
                    <div>{{ $products->links() }}</div>
                @endif
            @endif
        </div>
    </div>
@endsection
