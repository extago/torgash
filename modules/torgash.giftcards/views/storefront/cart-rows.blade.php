{{-- The gift cards on the basket, on the cart page. What each will pay is
     worked out at checkout, where the delivery is known — here, its balance. --}}
@foreach ($cartCards as $cartCard)
    <div class="flex justify-between items-center gap-2 text-sm">
        <span class="flex items-center gap-2">
            <x-icon name="gift" size="sm" class="opacity-60" />
            {{ __('Gift card :card', ['card' => $cartCard->masked()]) }}
            <button hx-delete="{{ route('giftcards.cart.remove', $cartCard->id) }}" hx-target="#cart-contents" hx-swap="innerHTML"
                    class="link text-xs opacity-60 hover:opacity-100">{{ __('Remove') }}</button>
        </span>
        <span class="opacity-70 whitespace-nowrap">{{ __('balance :amount', ['amount' => format_price((int) $cartCard->balance)]) }}</span>
    </div>
@endforeach
<p class="text-xs opacity-60 -mt-2">{{ __('A gift card pays at checkout, once the delivery is chosen.') }}</p>
