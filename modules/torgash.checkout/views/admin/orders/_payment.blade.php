{{-- The order stores the method's NAME, frozen at checkout, and a link to the
     method itself. The select offers the methods that exist today; an order
     whose method has since been deleted (or was placed before the link existed
     and renamed since) shows its frozen name instead of a selection. --}}
<form id="order-payment" class="flex flex-col gap-1"
      hx-patch="{{ route('admin.orders.payment', $order) }}" hx-target="#order-payment" hx-swap="outerHTML">
    @csrf
    {{-- Through the model's own lookup, so the select agrees with whatever the
         gateway resolution found — including for an old order matched by name. --}}
    @php
        $resolved = \Torgash\Checkout\Models\PaymentMethod::forOrder($order);
        $current = $resolved ? $paymentMethods->firstWhere('id', $resolved->id) : null;
    @endphp
    <select name="payment_method_id" class="select select-bordered w-full" data-submit-on-change>
        @unless ($current)
            <option value="" selected disabled>{{ $order->paymentMethodLabel() ?? '—' }}</option>
        @endunless
        @foreach ($paymentMethods as $method)
            <option value="{{ $method->id }}" @selected($current && $current->id === $method->id)>{{ $method->t('name') }}</option>
        @endforeach
    </select>
</form>
