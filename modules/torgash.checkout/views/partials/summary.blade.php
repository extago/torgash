{{-- The bill, for the method currently selected.

     Rendered by the server on every change rather than switched between
     precomputed JavaScript maps: with the destination now variable, those maps
     would have to be rebuilt on each change anyway, and one arithmetic — the
     one that also writes the order — is the point. --}}
@php
    $totals = app(\Torgash\Checkout\Totals::class);
    $extraLines = $totals->extra($cart, $quote, request()->input('payment_method_id') ?: old('payment_method_id'));
    $couponManager = app(\Torgash\Checkout\CouponManager::class);
    $coupon = $couponManager->current();
@endphp
{{-- The card around it, the form's problems and the button are the form's
     (form.blade.php): this is only what a re-quote replaces. --}}
<div id="checkout-summary" class="flex flex-col gap-2" @if ($oob ?? false) hx-swap-oob="true" @endif>
    <h2 class="text-lg font-semibold">{{ __('Your order') }}</h2>
    <div class="flex justify-between text-sm">
        <span class="opacity-70">{{ trans_choice('{1}:count item|[2,*]:count items', $cart->items()->sum('qty'), ['count' => $cart->items()->sum('qty')]) }}</span>
        <span>{{ format_price($quote->subtotal, exact: true) }}</span>
    </div>

    @if ($coupon)
        <div class="flex justify-between text-sm">
            <span class="opacity-70 flex items-center gap-1.5">
                {{ __('Coupon') }} <span class="badge badge-success badge-sm font-mono">{{ $coupon->code }}</span>
            </span>
            <span class="text-success">&minus;{{ format_price($couponManager->couponDiscount(), exact: true) }}</span>
        </div>
    @endif

    {{-- After the coupon: what other modules take off (bonus points), each
         a line of its own — they are all inside the discount the total
         and the tax are worked out from. --}}
    @foreach ($couponManager->otherDiscounts() as $otherDiscount)
        <div class="flex justify-between text-sm">
            <span class="opacity-70">{{ $otherDiscount['label'] }}</span>
            <span class="text-success">&minus;{{ format_price($otherDiscount['amount'], exact: true) }}</span>
        </div>
    @endforeach

    @if ($cart->needsDelivery())
    <div class="flex justify-between text-sm">
        <span class="opacity-70">
            {{ __('Shipping') }}
            @if ($quote->selected)
                <span class="block text-xs opacity-60">{{ $quote->selected->method->t('name') }}</span>
            @endif
        </span>
        {{-- A delivery still waiting for a price contributes 0 to the total
             but is not free, and saying "Free" here is the one place that
             lie would reach the customer as a promise. --}}
        <span class="{{ $quote->shippingPending() ? 'text-xs opacity-70' : '' }}">
            @if (! $quote->selected)
                —
            @elseif ($quote->shippingPending())
                {{ __('cost confirmed separately') }}
            @else
                {{ $quote->shippingCost() > 0 ? format_price($quote->shippingCost(), exact: true) : __('Free') }}
            @endif
        </span>
    </div>
    @endif

    @foreach ($extraLines as $line)
        <div class="flex justify-between text-sm">
            <span class="opacity-70">{{ $line['label'] }}</span>
            <span>{{ format_price($line['amount'], exact: true) }}</span>
        </div>
    @endforeach

    {{-- The coupon, above the total it changes. Its own little form (this
         card is outside the checkout form); applying it announces
         `coupon-changed`, the delivery step re-quotes, and this card comes
         back out of band with the new sum. --}}
    <div class="mt-2">@include('torgash.checkout::partials.checkout-coupon', ['coupon' => $coupon])</div>

    <div class="divider my-1"></div>

    @php
        $grand = $totals->grand($quote, $extraLines);
        // Paid before any gateway is asked — a gift card (PLAN §40.4). Below
        // the total, never inside it: it is not a discount, it is money the
        // customer already paid, and the total is what the goods cost.
        $prepaidLines = $totals->prepaidLines($cart, $grand, $quote->shippingCost());
        $leftToPay = max(0, $grand - array_sum(array_column($prepaidLines, 'amount')));
        // Part online now, the rest on delivery — when the method picked
        // takes it that way (PLAN §40.10/1); the same question PlaceOrder
        // asks, so the summary promises what the order will hold.
        $summaryMethodId = request()->input('payment_method_id') ?: old('payment_method_id') ?: ($summaryDefaultMethod ?? null);
        $summaryMethod = $summaryMethodId ? \Torgash\Checkout\Models\PaymentMethod::find($summaryMethodId) : null;
        $summaryDeposit = $summaryMethod && hook_filter('checkout.payment_method.online', false, ['method' => $summaryMethod])
            ? $summaryMethod->depositFor($leftToPay)
            : null;
    @endphp
    <div class="flex justify-between font-bold text-lg">
        <span>{{ __('Total') }}</span>
        <span>{{ format_price($grand, exact: true) }}</span>
    </div>

    @if ($prepaidLines)
        @foreach ($prepaidLines as $prepaidLine)
            <div class="flex justify-between items-center gap-2 text-sm">
                <span class="opacity-70 flex items-center gap-1.5 min-w-0">
                    <span class="truncate">{{ $prepaidLine['label'] }}</span>
                    @if (! empty($prepaidLine['remove_url']))
                        <button type="button" class="link text-xs opacity-60 hover:opacity-100 shrink-0"
                                hx-delete="{{ $prepaidLine['remove_url'] }}" hx-vals='{"in": "checkout"}' hx-swap="none">{{ __('Remove') }}</button>
                    @endif
                </span>
                <span class="whitespace-nowrap">&minus;{{ format_price($prepaidLine['amount'], exact: true) }}</span>
            </div>
            @if (! empty($prepaidLine['extra_html']))
                {!! $prepaidLine['extra_html'] !!}
            @endif
        @endforeach
        @unless ($summaryDeposit)
            <div class="flex justify-between font-semibold">
                <span>{{ __('To pay') }}</span>
                <span>{{ format_price($leftToPay, exact: true) }}</span>
            </div>
        @endunless
    @endif

    @if ($summaryDeposit)
        <div class="flex justify-between font-semibold">
            <span>{{ __('To pay online now') }}</span>
            <span>{{ format_price($summaryDeposit, exact: true) }}</span>
        </div>
        <div class="flex justify-between text-sm">
            <span class="opacity-70">{{ __('To pay on delivery') }}</span>
            <span>{{ format_price($leftToPay - $summaryDeposit, exact: true) }}</span>
        </div>
    @endif

    {{-- No "you will actually be charged in …" note here any more: the
         order is charged in the currency the customer is looking at, so
         the number above IS the number their card sees. --}}
</div>
