{{-- "The order is $1 042.25; the card will be charged ₴42 732."

     A payment method that cannot take the currency an order was quoted in
     charges in its own — that is the ordinary Ukrainian shop, pricing in
     dollars and holding a hryvnia merchant account. The order goes on being
     printed in the currency the customer agreed to (§51); this is the one line
     that says what the bank will actually take, at the rate frozen onto the
     order rather than today's.

     Renders nothing when the two agree, which is every single-currency shop.

     $order — the order --}}
@if ($order->paymentDiffers())
    @php
        $currencies = app(\App\Core\Support\Currencies::class);
        $symbol = fn (string $code) => $currencies->find($code)?->symbol ?: $code;

        // The rate between what the customer SEES and what they pay, which is
        // the only pair of numbers on the page: both stored rates are against
        // the shop's books, and quoting those would explain the conversion with
        // a currency the customer never saw.
        $rate = $order->chargeRate() > 0 ? $order->paymentRate() / $order->chargeRate() : 0;
    @endphp

    <div class="text-sm mt-1">
        {{ __('Payment is taken in :currency — :amount will be charged.', [
            'currency' => $order->paymentCurrency(),
            'amount' => $order->paymentMoney($order->chargeNow()),
        ]) }}

        @if ($rate > 0 && $rate !== 1.0)
            <span class="opacity-60">{{ __('Rate: 1 :from = :rate :to.', [
                'from' => $symbol($order->chargeCurrency()),
                'rate' => format_rate($rate, thousands: ' '),
                'to' => $symbol($order->paymentCurrency()),
            ]) }}</span>
        @endif
    </div>
@endif
