{{--
    A list somebody shared: its products, to look at and to buy — nobody's
    name, no "remove", no filters (a gift list is short). The owner opening
    their own link is told so, with the way to their editable page.
--}}
@extends('layouts.storefront')

@section('content')
    <x-breadcrumbs />
    <h1 class="text-2xl font-bold mb-4">{{ __('A wishlist shared with you') }}</h1>

    <div class="flex flex-col gap-4">
        @if ($own)
            <div class="alert">
                <x-icon name="info" />
                <span>{{ __('This is your own list, as others see it by the link.') }}</span>
                <a href="{{ route('wishlist.index') }}" class="btn btn-sm btn-touch">{{ __('Open the wishlist') }}</a>
            </div>
        @endif

        <div class="card bg-base-100 border border-base-300/60 shadow-sm">
            @if ($products->isEmpty())
                <div class="card-body text-center opacity-70 py-10">{{ __('This list is empty now.') }}</div>
            @else
                @php $wishShown = app(\Torgash\Catalog\Pricing\ShownPrice::class); @endphp
                <ul class="divide-y divide-base-300">
                    @foreach ($products as $wishRow)
                        @include('torgash.wishlist::row', ['wishRow' => $wishRow, 'wishShown' => $wishShown, 'wishPaths' => $paths, 'wishEditable' => false])
                    @endforeach
                </ul>
            @endif
        </div>

        @if ($products->hasPages())
            <div>{{ $products->links() }}</div>
        @endif
    </div>
@endsection
