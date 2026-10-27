{{-- Under a card's line in the checkout summary: "take no more than" — how a
     customer keeps part of a card for next time. Its own small form (the
     summary is outside the checkout form); applying it re-quotes. --}}
<details class="text-xs -mt-1" @if ($limit !== null) open @endif>
    <summary class="cursor-pointer opacity-60 hover:opacity-100">
        {{ __('Left on the card: :amount', ['amount' => format_price(max(0, (int) $card->balance - $taken))]) }} · {{ __('use less') }}
    </summary>
    <form class="flex gap-2 mt-1" method="POST" action="{{ route('giftcards.cart.limit', $card->id) }}"
          hx-post="{{ route('giftcards.cart.limit', $card->id) }}" hx-swap="none">
        @csrf
        <input type="hidden" name="in" value="checkout">
        <input type="text" inputmode="decimal" name="limit" class="input input-bordered input-sm w-full"
               value="{{ $limit !== null ? rtrim(rtrim(number_format($limit / 100, 2, '.', ''), '0'), '.') : '' }}"
               placeholder="{{ __('as much as it can') }}" aria-label="{{ __('Take no more than') }}">
        <button class="btn btn-sm">{{ __('Apply') }}</button>
    </form>
</details>
