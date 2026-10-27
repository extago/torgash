{{-- The link is signed (see the route): it must work from an email opened
     tomorrow, with no session, and must not be guessable from an order
     number. --}}
@if (session('payment_error'))
    {{-- Why the last attempt did not go anywhere. Before this, a refused
         invoice looked exactly like a button that does nothing, and the only
         trace was a log row the customer cannot see (§48 of the log). --}}
    <div class="alert alert-error mt-6 flex flex-col items-start gap-1">
        <span>{{ session('payment_error') }}</span>

        @php
            // The gateway's own words are about the shop's setup ("hold payments
            // are allowed only for UAH") or the bank's mood — useful to whoever
            // can act on it, noise or worse to the customer. Shown on a debug
            // machine, and to a member of staff looking at the page; everyone
            // else reads it in Payments → Log.
            $showReason = session('payment_error_reason')
                && (config('app.debug') || auth()->user()?->roles()->exists());
        @endphp

        @if ($showReason)
            <span class="text-xs opacity-80">{{ session('payment_error_reason') }}</span>
        @endif
    </div>
@endif

@if ($payable ?? true)
    <div class="alert alert-warning mt-6 flex flex-col sm:flex-row items-center gap-3">
        <span>{{ __('This order is not paid yet.') }}</span>
        {{-- The amount on the button is the one the bank will take, not the one
             the order is printed in: those differ whenever the method charges in
             a currency of its own, and a button promising $1 042.25 next to a
             page that bills ₴42 732 is the moment a customer stops trusting the
             shop. --}}
        <a href="{{ \Illuminate\Support\Facades\URL::signedRoute('payments.pay', $order->order_number) }}"
           class="btn btn-primary btn-sm">
            {{ __('Pay :amount', ['amount' => $order->paymentMoney($order->chargeNow())]) }}
        </a>
    </div>
@endif
