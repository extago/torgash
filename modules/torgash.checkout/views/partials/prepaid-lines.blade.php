{{-- How the order is paid, under the total it does not change: what was paid
     in advance (a gift card, PLAN §40.4) and, for an order sold with a deposit,
     what goes online and what on delivery (§40.10/1). One partial for the
     confirmation, the invoice and the admin, from one list
     (`Order::paymentSplit()`), so none of them tells it differently.
     `$prepaidMoney` prints an amount the way the caller prints the rest;
     `$prepaidWidth` is the column the caller uses. --}}
@foreach ($order->paymentSplit() as $splitRow)
    <div class="flex justify-between gap-2 {{ $splitRow['strong'] ? 'font-semibold' : 'text-sm' }} {{ $prepaidWidth ?? '' }}">
        <span @class(['opacity-70' => ! $splitRow['strong']])>{{ $splitRow['label'] }}</span>
        <span class="whitespace-nowrap">@if ($splitRow['minus'])&minus;@endif{{ $prepaidMoney($splitRow['amount']) }}</span>
    </div>
@endforeach
