{{-- "The basket has changed": everything the requesting page shows of it,
     each out of band and only where the page has it (CartController::SHOWS —
     cart.js says which). The answer to an add — a card's button, the buy box,
     a kit — whose form itself swaps nothing (`hx-swap="none"`): the header
     badge, the phone tab bar's count, and on the basket page the basket. --}}
@php
    $cartChangedShows = \Torgash\Cart\Http\CartController::shows(request());
    $cartChanged = app(\Torgash\Cart\CartManager::class);
@endphp
@if (in_array('badge', $cartChangedShows, true))
    @include('torgash.cart::partials.badge', ['cartBadgeOob' => true])
@endif
@if (in_array('tabbar', $cartChangedShows, true))
    <span id="tabbar-badge-cart" hx-swap-oob="true" @class(['badge badge-primary torgash-tabbar-badge', 'hidden' => $cartChanged->count() === 0])>{{ $cartChanged->count() ?: '' }}</span>
@endif
@if (in_array('contents', $cartChangedShows, true))
    <div id="cart-contents" hx-swap-oob="innerHTML">
        @include('torgash.cart::partials.contents', ['cart' => $cartChanged])
    </div>
@endif
