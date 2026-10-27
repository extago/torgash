{{-- The delivery estimate under the cart total.

     A form of its own, sitting beside the coupon form rather than inside it —
     nesting them would let one submit carry the other's fields. Picking a town
     re-posts here and swaps this whole block, which is all that changes: the
     cart total deliberately does not include shipping (see the hook listener in
     CheckoutServiceProvider). --}}
<form id="cart-delivery" class="flex flex-col gap-2 pt-3 border-t border-base-300"
      hx-post="{{ route('cart.delivery.estimate') }}"
      hx-trigger="destination-changed"
      hx-target="#cart-delivery" hx-swap="outerHTML">
    @csrf

    <div class="flex items-baseline justify-between gap-2">
        <span class="text-xs font-semibold uppercase tracking-wide opacity-50">{{ __('Delivery') }}</span>
        @if ($quote->zone && $quote->isAddressed())
            <span class="text-xs opacity-50">{{ $quote->zone->t('name') }}</span>
        @endif
    </div>

    @include('torgash.shipping::partials.town-field', [
        'town' => $town,
        'placeId' => $quote->location->placeId,
        'country' => $quote->location->country,
        'size' => 'input-sm',
    ])

    @include('torgash.shipping::partials.delivery-options', ['quote' => $quote])

    @unless ($quote->isAddressed())
        <div class="text-xs opacity-50">{{ __('Enter your town to see what is available there.') }}</div>
    @endunless
</form>
