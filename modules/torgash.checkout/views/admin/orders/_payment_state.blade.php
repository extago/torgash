@php use Torgash\Checkout\Orders\PaymentState; @endphp

{{-- Where the money is, and how much of it. Separate from the fulfilment
     status above on purpose: an order can be paid and unshipped, or shipped and
     unpaid, and one select cannot say both. --}}
<form id="order-payment-state" class="flex flex-col gap-1"
      hx-patch="{{ route('admin.orders.payment-state', $order) }}" hx-target="#order-payment-state" hx-swap="outerHTML">
    @csrf
    <div class="flex items-center gap-2">
        <select name="payment_state" class="select select-bordered grow"
                x-data x-on:change="$el.form.querySelector('[name=paid_total]').classList.toggle('hidden', !$el.options[$el.selectedIndex].dataset.money)">
            @foreach (PaymentState::all() as $state)
                <option value="{{ $state }}" @selected($order->payment_state === $state)
                        data-money="{{ PaymentState::holdsMoney($state) ? '1' : '' }}">
                    {{ PaymentState::label($state) }}
                </option>
            @endforeach
        </select>
        <input type="text" name="paid_total" inputmode="decimal"
               value="{{ number_format($order->paid_total / 100, 2, '.', '') }}"
               placeholder="{{ number_format($order->total / 100, 2, '.', '') }}"
               class="input input-bordered w-28 @unless (PaymentState::holdsMoney($order->payment_state)) hidden @endunless">
        <button class="btn btn-primary">{{ __('Save') }}</button>
    </div>

    @if ($order->balance() !== 0 && PaymentState::holdsMoney($order->payment_state))
        {{-- The one number an admin has to see before deciding to refund or to
             chase: editing a paid order's contents moves the total under the
             payment, and neither side of that is wrong by itself. --}}
        <div class="text-xs {{ $order->balance() > 0 ? 'text-warning' : 'text-error' }}">
            {{ $order->balance() > 0 ? __('Still owed') : __('Overpaid') }}:
            {{ admin_price(abs($order->balance()), $order->currency_code) }}
        </div>
    @endif

    @if ($order->prepaid_total > 0)
        {{-- Inside the amount above; the part no bank holds, and what a
             refund gives back first (PLAN §40.4). --}}
        <div class="text-xs opacity-70">
            {{ __('Of it paid in advance: :amount', ['amount' => admin_price($order->prepaid_total, $order->currency_code)]) }}
        </div>
    @endif

    @if ($order->paid_at)
        <div class="text-xs opacity-60">{{ __('Paid on') }}: {{ $order->paid_at->format('Y-m-d H:i') }}</div>
    @endif

    {{-- The three currencies of this order, said only where they part company:
         the books it is stored in, the one the customer agreed to, and the one
         the bank is asked for. The amounts above and the field beside them are
         all in the FIRST, which is why this has to be readable from here. --}}
    @if ($order->charge_currency || $order->payment_currency)
        <div class="text-xs opacity-60">
            {{ __('Books: :books.', ['books' => $order->currency_code]) }}
            @if ($order->charge_currency)
                {{ __('Quoted to the customer in :currency at :rate.', [
                    'currency' => $order->charge_currency,
                    'rate' => rtrim(rtrim(number_format($order->chargeRate(), 4, '.', ' '), '0'), '.'),
                ]) }}
            @endif
            @if ($order->paymentDiffers())
                {{ __('Charged in :currency: :amount.', [
                    'currency' => $order->paymentCurrency(),
                    'amount' => $order->paymentMoney($order->gatewayDue()),
                ]) }}
            @endif
        </div>
    @endif
</form>
