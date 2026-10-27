{{-- The order's payments, each with the gateway's own buttons for IT: an order
     sold with a deposit whose rest was paid online has two invoices, and
     capturing, releasing or refunding is always about one of them. Drawn from
     what the gateway can do and what the payment is (PaymentActions::available)
     — a hold that cannot be captured offers no Capture button. Rendered into
     the order card through checkout.order.payment_extra, so checkout knows
     nothing about any of this.

     Each button is its own <form> BESIDE the others, never nested: an inner
     form is dropped by the parser while its inputs stay, and a stray _method
     would ride out on the wrong submit. --}}
<div class="mt-2 flex flex-col gap-1">
    <div class="text-xs opacity-60">
        {{ $binding->gateway }}
        @if ($binding->test_mode)
            · <span class="text-warning">{{ __('Test mode') }}</span>
        @endif
    </div>

    @foreach ($payments as $row)
        @php($payment = $row['payment'])
        <div class="flex flex-wrap items-center gap-1 text-xs" data-payment="{{ $payment->id }}">
            <span class="badge badge-xs {{ $payment->held() > 0 ? 'badge-success' : 'badge-ghost' }}">{{ \Torgash\Checkout\Orders\PaymentState::label($payment->state) }}</span>
            <span class="font-mono opacity-60" title="{{ $payment->external_id }}">{{ \Illuminate\Support\Str::limit((string) $payment->external_id, 14) }}</span>
            @if ($payment->held() > 0)
                <span>{{ $order->adminMoney($payment->held()) }}</span>
            @elseif ($payment->requested > 0)
                <span class="opacity-60">{{ $order->adminMoney($payment->requested) }}</span>
            @endif
            @if ($payment->order_id !== $order->id)
                <span class="opacity-60">{{ __('from order :number', ['number' => $payment->order->order_number]) }}</span>
            @endif

            @foreach ($row['actions'] as $capability)
                <form method="POST" action="{{ route('admin.payments.act', [$order, $capability]) }}">
                    @csrf
                    <input type="hidden" name="payment" value="{{ $payment->id }}">
                    <button class="btn btn-xs">{{ match ($capability) {
                        'void' => __('Release the hold'),
                        'capture' => __('Capture'),
                        'refund' => __('Refund'),
                        'cancel' => __('Cancel'),
                        default => __(ucfirst($capability)),
                    } }}</button>
                </form>
            @endforeach
        </div>
    @endforeach

    @if ($refresh)
        <form method="POST" action="{{ route('admin.payments.act', [$order, 'refresh']) }}">
            @csrf
            <button class="btn btn-xs">{{ __('Refresh') }}</button>
        </form>
    @endif

    @if ($transactions->isNotEmpty())
        <details class="text-xs mt-1">
            <summary class="cursor-pointer opacity-60">{{ __('Payment messages') }}</summary>
            @foreach ($transactions as $transaction)
                <div class="flex gap-2 mt-1">
                    <span class="opacity-50">{{ $transaction->created_at?->format('m-d H:i') }}</span>
                    <span class="badge badge-xs {{ $transaction->badge() }}">{{ $transaction->state }}</span>
                    <span class="opacity-70">{{ $transaction->summary }}</span>
                </div>
            @endforeach
            <a href="{{ route('admin.payments.log', ['order' => $order->id]) }}" class="link link-primary">
                {{ __('All of them') }} &rarr;
            </a>
        </details>
    @endif
</div>
