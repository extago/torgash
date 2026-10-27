{{-- The header's heart with its count (the theme's storefront.header.icons),
     and the same element re-sent out of band after every toggle
     (wishCounterOob). --}}
@php $wishCount = app(\Torgash\Wishlist\Wishlists::class)->count(); @endphp
<a href="{{ route('wishlist.index') }}" id="wishlist-counter" class="btn btn-ghost btn-sm gap-2 px-3"
   @if ($wishCounterOob ?? false) hx-swap-oob="true" @endif
   aria-label="{{ __('Wishlist') }}{{ $wishCount > 0 ? ' ('.$wishCount.')' : '' }}" title="{{ __('Wishlist') }}">
    <span class="torgash-counter">
        <x-icon name="heart" size="lg" />
        <span @class(['badge badge-sm', 'badge-primary' => $wishCount > 0, 'torgash-counter-zero' => $wishCount === 0])>{{ $wishCount > 9 ? '9+' : $wishCount }}</span>
    </span>
</a>
@if ($wishCounterOob ?? false)
    {{-- ...and the phone tab bar's count (PLAN §38). --}}
    <span id="tabbar-badge-wishlist" hx-swap-oob="true" @class(['badge badge-primary torgash-tabbar-badge', 'hidden' => $wishCount === 0])>{{ $wishCount ?: '' }}</span>
@endif
