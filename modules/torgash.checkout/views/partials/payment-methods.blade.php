{{-- The payment methods offered to THIS order (PLAN §66: the buyer, the
     customer's group, the delivery, the currency) — drawn in the form and
     again, out of band, with every re-quote: a delivery picked or a company
     ticked can take a method away or bring one. The radios keep their ids,
     so htmx puts the focus back where the customer had it.

     `$checkoutPayment` is PlaceOrder::checkoutChoice(): the methods and the
     one counted as chosen (what the summary beside it prices). --}}
@php
    $paymentTotals = app(\Torgash\Checkout\Totals::class);
@endphp
<div id="checkout-payments" class="flex flex-col gap-2" @if ($oob ?? false) hx-swap-oob="true" @endif>
    @forelse ($checkoutPayment['methods'] as $method)
        @php
            $charge = $method->chargeCurrencyFor(current_currency());
            $surchargeHint = \Torgash\Checkout\Orders\PaymentSurcharge::hint(
                $method,
                $method->surcharge_display === 'amount' ? $paymentTotals->surchargeOf($method, $quote, null, $cart) : null,
                fn (int $amount) => format_price($amount, exact: true),
            );
        @endphp
        <label class="flex items-center gap-3 border border-base-300 rounded-lg px-4 py-3 cursor-pointer has-[:checked]:border-primary has-[:checked]:bg-primary/5">
            <input type="radio" id="checkout-payment-{{ $method->id }}" name="payment_method_id" value="{{ $method->id }}" class="radio radio-primary"
                   @checked($checkoutPayment['chosen'] === $method->id) required>
            @if ($method->logoUrl())
                <img src="{{ $method->logoUrl() }}" alt="" class="h-6 w-10 object-contain shrink-0">
            @endif
            <span class="flex flex-col">
                <span>{{ $method->t('name') }}
                    @if ($surchargeHint)
                        <span id="checkout-surcharge-{{ $method->id }}" class="text-xs opacity-70">{{ $surchargeHint }}</span>
                    @endif
                </span>
                {{-- Said before the order exists, not on the payment page: a
                     method that cannot take the currency on screen still
                     charges a real amount, and finding that out afterwards
                     is what makes a customer abandon a placed order. --}}
                @if ($charge !== current_currency())
                    <span class="text-xs opacity-60">
                        {{ __('The payment itself goes through in :currency, converted at the current rate.', ['currency' => $charge]) }}
                    </span>
                @endif
            </span>
        </label>
    @empty
        <div class="alert alert-warning text-sm">{{ __('No payment method is offered for this order. Try another delivery.') }}</div>
    @endforelse
</div>
