@extends('layouts.storefront')

@section('title', __('Cart').' · '.current_storefront()?->name)

@section('content')
    <a href="{{ url()->previous() !== url()->current() && str_starts_with(url()->previous(), url('/')) ? url()->previous() : route('catalog.index') }}"
       class="inline-flex items-center gap-1 text-sm opacity-60 hover:opacity-100">
        <x-icon name="chevron-left" size="sm" /> {{ __('Back to shopping') }}
    </a>
    <h1 class="text-3xl font-bold mt-3 mb-6">{{ __('Cart') }}</h1>
    <div id="cart-contents">
        @include('torgash.cart::partials.contents', ['cart' => $cart])
    </div>

    {{-- Under the basket, whatever the shop places here — the theme puts
         "You may also like" (a products block). Outside #cart-contents, so a
         change of quantity does not redraw it. --}}
    @php $cartZoneBottom = zone('cart.page.bottom', ['cart' => $cart]); @endphp
    @if (trim($cartZoneBottom) !== '')
        <div class="mt-12">{!! $cartZoneBottom !!}</div>
    @endif
@endsection
