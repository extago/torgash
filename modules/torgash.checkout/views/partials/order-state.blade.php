{{-- Where an order is, in two badges: the shop's own workflow status and the
     fixed payment state beside it.

     They are two axes on purpose (see PaymentState) and the customer needs both
     for the same reason the manager does — "Processing" says nothing about
     whether the money arrived, and a shop whose statuses are named after
     shipping cannot express "paid" at all. The status name and colour come from
     the shop's table through the per-request memo, so a list of twenty orders
     still reads it once.

     $order  — the order
     $inline — true to lay both out on one line (a table cell) --}}
@php
    $statuses = app(\Torgash\Checkout\Support\Statuses::class);
    $state = $order->payment_state;

    $statusLabel = $statuses->label($order->status);
    $paymentLabel = \Torgash\Checkout\Orders\PaymentState::label($state);
@endphp

<div class="flex {{ ($inline ?? false) ? 'items-center' : 'flex-col items-start' }} gap-1.5">
    <span class="inline-flex items-center gap-1.5 whitespace-nowrap">
        <span class="inline-block h-2 w-2 rounded-full shrink-0" style="background-color: {{ $statuses->color($order->status) }}"></span>
        {{ $statusLabel }}
    </span>

    {{-- Dropped when it would repeat the status word for word — an order in
         "Awaiting payment" beside a badge reading "Awaiting payment" says one
         thing twice. Compared on the rendered label rather than on the codes,
         so it also holds for a shop that names a status after a payment state
         of its own accord. --}}
    @if ($paymentLabel !== $statusLabel)
        <span class="badge badge-sm {{ \Torgash\Checkout\Orders\PaymentState::badge($state) }} whitespace-nowrap">
            {{ $paymentLabel }}
        </span>
    @endif
</div>
