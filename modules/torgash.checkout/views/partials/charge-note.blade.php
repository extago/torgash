{{-- "This order is in dollars, and you are currently browsing in hryvnia."

     An order is quoted in the currency the customer was shopping in when they
     placed it, and printed in that currency for ever after — at the rate frozen
     onto it, so a reprint matches the receipt. The storefront switcher goes on
     working meanwhile, and someone who flips it afterwards would otherwise be
     looking at a card that disagrees with the header for no visible reason.

     Two different things are said here, and only when they are true:
       - the amounts above are in the order's own currency, not today's pick;
       - what that is worth today, marked as approximate, because it is not what
         anybody will be charged.

     Renders nothing at all in the ordinary case, where the two are the same.

     $order — the order --}}
@php
    $shown = current_currency();
    $currency = $order->chargeCurrency();

    $currencies = app(\App\Core\Support\Currencies::class);
    // Symbols read straight off the currency table rather than through the
    // shipping module's Money helper: delivery is optional and checkout may
    // not reach into it.
    $symbol = fn (string $code) => $currencies->find($code)?->symbol ?: $code;
@endphp

@if ($shown !== $currency)
    <div class="text-xs opacity-60 mt-1 flex flex-col gap-0.5">
        <span>{{ __('This order is in :currency — the currency it was placed in.', ['currency' => $currency]) }}</span>

        {{-- Today's rate, and said to be today's: it is a courtesy for a
             visitor reading in another currency, never the amount owed.

             Skipped when the payment line above has just printed the same
             number in the same currency — a visitor browsing in hryvnia who is
             also being charged in hryvnia does not need to be told twice, and
             the second telling reads as a second, different amount. --}}
        @unless ($order->paymentDiffers() && $order->paymentCurrency() === $shown)
            <span>{{ __('About :amount at today\'s rate.', ['amount' => format_price($order->total)]) }}</span>
        @endunless

        @if ($order->charge_rate && (float) $order->charge_rate !== 1.0)
            {{-- The rate it was quoted at, not today's: that is what makes the
                 total above checkable a month later. --}}
            <span>{{ __('Rate at the time: 1 :from = :rate :to.', [
                'from' => $symbol($order->currency_code),
                'rate' => format_rate($order->charge_rate, thousands: ' '),
                'to' => $symbol($currency),
            ]) }}</span>
        @endif
    </div>
@endif
