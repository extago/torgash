{{--
    What the order IS, as a block of a letter (CheckoutLetters, {order_details}):
    delivery and payment, the lines as OrderLines::tree() prints them everywhere,
    the total, the amount the bank takes, the address. Inline styles only —
    it lands inside core's mail frame.
--}}
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px; font-size:14px;">
    <tr><td style="padding:2px 0; color:#64748b;">{{ __('Order number') }}</td><td style="padding:2px 0; text-align:right; font-weight:600;">{{ $order->order_number }}</td></tr>
    @if ($order->hasDelivery())
        <tr><td style="padding:2px 0; color:#64748b;">{{ __('Shipping') }}</td><td style="padding:2px 0; text-align:right;">{{ $order->shipping_method_name }}</td></tr>
    @endif
    <tr><td style="padding:2px 0; color:#64748b;">{{ __('Payment') }}</td><td style="padding:2px 0; text-align:right;">{{ $order->payment_method_name }}</td></tr>
</table>

@if ($order->deliveryPending())
    {{-- Said before the totals: the customer is about to read a total that
         does not include delivery yet. --}}
    <p style="margin:0 0 14px; background:#fff6e5; padding:10px 12px; border-radius:8px; font-size:14px;">
        {{ __('The delivery cost is not in the total below — we will confirm it with you and let you know.') }}
    </p>
@endif

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse; margin:0 0 8px; font-size:14px;">
    <tr>
        <th align="left" style="padding:8px 0; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600;">{{ __('Product') }}</th>
        <th align="center" style="padding:8px 6px; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600;">{{ __('Qty') }}</th>
        <th align="right" style="padding:8px 0; border-bottom:2px solid #e2e8f0; color:#64748b; font-weight:600;">{{ __('Total') }}</th>
    </tr>
    @foreach (\Torgash\Checkout\Orders\OrderLines::tree($order->items ?? []) as $node)
        @foreach ([$node['line'], ...$node['children']] as $itemIndex => $item)
            <tr>
                <td style="padding:8px 0; border-bottom:1px solid #f1f5f9; {{ $itemIndex > 0 ? 'padding-left:16px; color:#64748b;' : '' }}">
                    @if ($itemIndex > 0)&rarr;@endif
                    {{ $item['name'] }}
                    @foreach ($item['options'] ?? [] as $option)
                        @php $optionSign = ($option['amount'] ?? 0) < 0 ? '−' : '+'; @endphp
                        <div style="font-size:12px; color:#64748b;">{{ $optionSign }} {{ $option['label'] }}{{ ($option['qty'] ?? 1) > 1 ? ' ×'.$option['qty'] : '' }}</div>
                    @endforeach
                </td>
                <td align="center" style="padding:8px 6px; border-bottom:1px solid #f1f5f9;">{{ $item['qty'] }}</td>
                <td align="right" style="padding:8px 0; border-bottom:1px solid #f1f5f9; white-space:nowrap;">{{ $order->money($item['line_total']) }}</td>
            </tr>
        @endforeach
    @endforeach
    <tr>
        <td colspan="2" style="padding:12px 0 4px; font-weight:700;">{{ __('Total') }}</td>
        <td align="right" style="padding:12px 0 4px; font-weight:700; white-space:nowrap;">{{ $order->money($order->total) }}</td>
    </tr>
    {{-- How it is paid: in advance, online now, on delivery — Order::paymentSplit(). --}}
    @foreach ($order->paymentSplit() as $mailSplit)
        <tr>
            <td colspan="2" style="padding:3px 0; {{ $mailSplit['strong'] ? 'font-weight:600;' : 'color:#64748b;' }}">{{ $mailSplit['label'] }}</td>
            <td align="right" style="padding:3px 0; white-space:nowrap; {{ $mailSplit['strong'] ? 'font-weight:600;' : '' }}">@if ($mailSplit['minus'])&minus;@endif{{ $order->money($mailSplit['amount']) }}</td>
        </tr>
    @endforeach
</table>

{{-- The amount the bank will take, when it is not the amount above. --}}
<div style="text-align:right; font-size:13px; color:#64748b; margin:0 0 14px;">
    @include('torgash.checkout::partials.payment-note', ['order' => $order])
</div>

@if ($order->address_line || $order->city)
    @php
        $orderAddress = trim(implode(', ', array_filter([$order->address_line, trim(($order->city ?? '').' '.($order->postal_code ?? '')), $order->countryName()])));
    @endphp
    <p style="margin:0 0 14px; font-size:14px;"><span style="color:#64748b;">{{ __('Delivery address') }}:</span> {{ $orderAddress }}</p>
@endif
