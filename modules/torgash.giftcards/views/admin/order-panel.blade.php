{{-- On the order's payment card (checkout.order.payment_extra): which gift
     cards paid it, putting one on ("the customer forgot") and taking one off,
     and the cards this order BOUGHT. Each form stands beside the others,
     never inside one. --}}
<div class="mt-3 flex flex-col gap-2 border-t border-base-300 pt-3">
    <div class="text-xs font-semibold opacity-70 flex items-center gap-1.5"><x-icon name="gift" size="sm" /> {{ __('Gift cards') }}</div>

    @foreach ($paidCards as $paid)
        <div class="flex items-center justify-between gap-2 text-sm">
            <a href="{{ route('admin.giftcards.show', $paid['card']) }}" class="link link-hover font-mono">{{ $paid['card']->masked() }}</a>
            <span class="flex items-center gap-2">
                <span class="tabular-nums">&minus;{{ admin_price($paid['amount'], $order->currency_code) }}</span>
                <form method="POST" action="{{ route('admin.giftcards.orders.detach', [$order, $paid['card']]) }}"
                      data-confirm="{{ __('Take this card off the order? What it paid goes back onto it.') }}">
                    @csrf
                    <button class="btn btn-ghost btn-sm text-error" title="{{ __('Take it off the order') }}"><x-icon name="x" size="sm" /></button>
                </form>
            </span>
        </div>
    @endforeach

    @if ($order->balance() > 0)
        <form method="POST" action="{{ route('admin.giftcards.orders.attach', $order) }}" class="flex flex-col gap-1">
            @csrf
            <div class="join w-full">
                <input name="code" required maxlength="40" autocomplete="off" class="input input-bordered input-sm join-item w-full font-mono uppercase"
                       placeholder="{{ __('Gift card code') }}">
                <input name="limit" inputmode="decimal" class="input input-bordered input-sm join-item w-24" placeholder="{{ __('all') }}"
                       title="{{ __('Take no more than') }}">
                <button class="btn btn-sm join-item">{{ __('Pay') }}</button>
            </div>
            @error('giftcard_code')
                <p class="text-error text-xs">{{ $message }}</p>
            @enderror
        </form>
    @endif

    @if ($soldCards->isNotEmpty())
        <div class="text-xs opacity-70 mt-1">{{ __('Bought by this order') }}:</div>
        @foreach ($soldCards as $soldCard)
            <div class="flex items-center justify-between gap-2 text-sm">
                <a href="{{ route('admin.giftcards.show', $soldCard) }}" class="link link-hover font-mono">{{ $soldCard->masked() }}</a>
                <span class="badge badge-sm {{ \Torgash\Giftcards\Models\Card::stateBadge($soldCard->state()) }}">{{ \Torgash\Giftcards\Models\Card::stateLabel($soldCard->state()) }}</span>
            </div>
        @endforeach
    @endif
</div>
