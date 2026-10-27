{{-- A signed-in customer's own cards, one click each — no code to type. --}}
<div class="flex flex-col gap-1 text-sm mt-2">
    <span class="text-xs opacity-60">{{ __('Your gift cards') }}</span>
    <div class="flex flex-wrap gap-1.5">
        @foreach ($ownCards as $ownCard)
            <form method="POST" action="{{ route('giftcards.cart.own', $ownCard->id) }}"
                  hx-post="{{ route('giftcards.cart.own', $ownCard->id) }}"
                  @if ($in === 'checkout') hx-swap="none" @else hx-target="#cart-contents" hx-swap="innerHTML" @endif>
                @csrf
                <input type="hidden" name="in" value="{{ $in }}">
                <button class="btn btn-sm gap-1.5">
                    <x-icon name="gift" size="sm" /> {{ $ownCard->masked() }} · {{ format_price((int) $ownCard->balance) }}
                </button>
            </form>
        @endforeach
    </div>
</div>
