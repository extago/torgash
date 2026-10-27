{{--
    The order's own page — what was bought, for how much, where it goes, how
    it is paid and where it has got to. One page for every way in: the owner
    reads it inside the account (its menu beside it), a guest by the signed
    link on its own, and the customer on the way out of checkout with a
    "thank you" over it.

    $inAccount  — the signed-in owner: the account's menu and a way back to the orders
    $justPlaced — just out of checkout
    $products   — the lines' products by id (pictures, pages), one query
    $history    — [{label, at}] what the customer is told of the order's way
--}}
@extends('layouts.storefront')

@section('title', __('Order :number', ['number' => $order->order_number]).' · '.current_storefront()?->name)

@section('content')
    @php
        $orderTree = \Torgash\Checkout\Orders\OrderLines::tree($order->items ?? []);
        $orderQty = fn ($qty) => rtrim(rtrim(number_format((float) $qty, 3, '.', ''), '0'), '.');
    @endphp

    <div @class(['flex flex-col md:flex-row gap-6' => $inAccount, 'max-w-5xl mx-auto' => ! $inAccount])>
        @if ($inAccount)
            @include('account._nav')
        @endif

        <div class="flex-1 min-w-0 flex flex-col gap-4">
            @if ($justPlaced)
                <div class="card bg-base-100 border border-success/40 shadow-sm">
                    <div class="card-body flex-row items-center gap-4 p-4 sm:p-5">
                        <span class="h-12 w-12 rounded-full bg-success/10 text-success grid place-items-center shrink-0"><x-icon name="check" size="lg" /></span>
                        <div>
                            <div class="text-lg font-bold">{{ __('Thank you!') }}</div>
                            <p class="opacity-70">{{ __('Your order has been placed.') }}</p>
                        </div>
                    </div>
                </div>
            @endif

            {{-- The heading: number (copied with one click), date, where it is.
                 On the page itself, not in a card — the same heading every
                 account page has ("Orders", "Enquiries"), so the order reads
                 as one of them. --}}
            <div class="flex flex-wrap items-start gap-x-4 gap-y-2">
                <div class="flex items-start gap-1 flex-1 min-w-0">
                    @if ($inAccount)
                        <a href="{{ route('account.orders') }}" class="btn btn-ghost btn-sm btn-square -ms-2 shrink-0" title="{{ __('Orders') }}" aria-label="{{ __('Orders') }}"><x-icon name="arrow-left" size="sm" /></a>
                    @endif
                    <div class="min-w-0">
                        <h1 class="text-xl font-bold flex items-center gap-1 whitespace-nowrap leading-9" x-data="{ copied: false }">
                            {{ __('Order :number', ['number' => $order->order_number]) }}
                            <button type="button" class="btn btn-ghost btn-sm btn-square opacity-60 hover:opacity-100" title="{{ __('Copy the number') }}" aria-label="{{ __('Copy the number') }}"
                                    x-on:click="navigator.clipboard?.writeText(@js($order->order_number)); copied = true; setTimeout(() => copied = false, 1500)">
                                <x-icon name="copy" size="sm" x-show="! copied" />
                                <x-icon name="check" size="sm" class="text-success" x-show="copied" x-cloak />
                            </button>
                        </h1>
                        <div class="text-sm opacity-60">{{ __('Placed') }} {{ $order->created_at->format('d.m.Y, H:i') }}</div>
                    </div>
                </div>
                <div class="text-sm w-full sm:w-auto sm:pt-2">@include('torgash.checkout::partials.order-state', ['order' => $order, 'inline' => true])</div>
            </div>

            <div class="grid lg:grid-cols-[minmax(0,1fr)_18rem] gap-4 items-start">
                <div class="flex flex-col gap-4 min-w-0">
                    {{-- What was bought. --}}
                    <section class="card bg-base-100 border border-base-300/60 shadow-sm">
                        <ul class="divide-y divide-base-300/60">
                            @foreach ($orderTree as $node)
                                @foreach ([$node['line'], ...$node['children']] as $itemIndex => $item)
                                    @php
                                        $itemProduct = $products[$item['product_id'] ?? 0] ?? null;
                                        $itemPicture = $itemProduct?->primaryImageUrl(160);
                                        $itemUrl = $itemProduct ? route('catalog.product', $itemProduct->slug) : null;
                                        $itemName = $order->itemLabel($item);
                                        $itemUnit = $item['unit_price'] ?? (($item['qty'] ?? 0) > 0 ? (int) round(($item['line_total'] ?? 0) / $item['qty']) : null);
                                    @endphp
                                    <li @class(['flex gap-3 sm:gap-4 p-4 sm:px-5', 'ps-10 sm:ps-16 border-t-0 pt-0' => $itemIndex > 0])>
                                        <a @if ($itemUrl) href="{{ $itemUrl }}" @endif
                                           @class(['rounded-field border border-base-300/70 bg-base-200 overflow-hidden grid place-items-center shrink-0', 'w-16 h-16 sm:w-20 sm:h-20' => $itemIndex === 0, 'w-10 h-10' => $itemIndex > 0])>
                                            @if ($itemPicture)
                                                <img src="{{ $itemPicture }}" alt="{{ $itemName }}" class="w-full h-full object-contain" loading="lazy">
                                            @else
                                                <x-icon name="package" class="opacity-30" />
                                            @endif
                                        </a>
                                        <div class="flex-1 min-w-0 flex flex-col sm:flex-row sm:items-start gap-1 sm:gap-4">
                                            <div class="flex-1 min-w-0">
                                                @if ($itemUrl)
                                                    <a href="{{ $itemUrl }}" class="link link-hover {{ $itemIndex > 0 ? 'text-sm' : '' }}">{{ $itemName }}</a>
                                                @else
                                                    <span class="{{ $itemIndex > 0 ? 'text-sm' : '' }}">{{ $itemName }}</span>
                                                @endif
                                                @if (! empty($item['options']))
                                                    <div class="text-xs opacity-60 mt-0.5">
                                                        @foreach ($item['options'] as $option)
                                                            <span>{{ $option['label'] }}</span>@if (! $loop->last) · @endif
                                                        @endforeach
                                                    </div>
                                                @endif
                                                <div class="text-sm opacity-60 mt-0.5">
                                                    {{ $orderQty($item['qty'] ?? 1) }} {{ __('pcs') }}
                                                    @if ($itemUnit !== null) × {{ $order->money($itemUnit) }} @endif
                                                    @if (! empty($item['sku'])) <span class="ms-2">{{ __('SKU') }}: {{ $item['sku'] }}</span> @endif
                                                </div>
                                            </div>
                                            <div class="font-semibold whitespace-nowrap sm:text-right">{{ $order->money($item['line_total'] ?? 0) }}</div>
                                        </div>
                                    </li>
                                @endforeach
                            @endforeach
                        </ul>

                        {{-- The sums, in the currency this order was charged in. --}}
                        <div class="border-t border-base-300/60 p-4 sm:px-5 flex flex-col gap-1.5 text-sm">
                            <div class="flex justify-between gap-4">
                                <span class="opacity-70">{{ __('Subtotal') }}</span>
                                <span>{{ $order->money($order->subtotal) }}</span>
                            </div>
                            @foreach ($order->discountLines() as $discountLine)
                                <div class="flex justify-between gap-4 text-success">
                                    <span>{{ $discountLine['label'] }}</span>
                                    <span class="whitespace-nowrap">&minus;{{ $order->money($discountLine['amount']) }}</span>
                                </div>
                            @endforeach
                            @if ($order->hasDelivery())
                                <div class="flex justify-between gap-4">
                                    <span class="opacity-70">{{ __('Shipping') }}</span>
                                    <span @class(['text-success' => $order->shipping_cost <= 0])>{{ $order->shipping_cost > 0 ? $order->money($order->shipping_cost) : __('Free') }}</span>
                                </div>
                            @endif
                            {{-- Lines other modules charged, read back from what this order
                                 was actually charged rather than recalculated at today's
                                 rates. An "included" line is already inside Total. --}}
                            @foreach (hook_filter('checkout.order.extra_totals', [], ['order' => $order]) as $line)
                                <div class="flex justify-between gap-4 {{ $line['included'] ? 'opacity-60' : '' }}">
                                    <span class="opacity-70">{{ $line['label'] }}</span>
                                    <span>{{ $order->money($line['amount']) }}</span>
                                </div>
                            @endforeach
                            <div class="flex justify-between gap-4 font-bold text-lg pt-1.5 mt-1 border-t border-base-300/60">
                                <span>{{ __('Total') }}</span>
                                <span>{{ $order->money($order->total) }}</span>
                            </div>
                            @include('torgash.checkout::partials.prepaid-lines', ['order' => $order, 'prepaidMoney' => fn (int $a) => $order->money($a)])
                            {{-- What the bank will be asked for, when the method the
                                 customer picked cannot take the currency the order
                                 is printed in; and a line saying so when the header
                                 now shows another currency. --}}
                            @include('torgash.checkout::partials.payment-note', ['order' => $order])
                            @include('torgash.checkout::partials.charge-note', ['order' => $order])
                        </div>

                        <div class="border-t border-base-300/60 p-4 sm:px-5 flex flex-wrap gap-2">
                            {{-- Signed like the page itself: a guest reading this by key
                                 has no session to authorise a second unsigned address. --}}
                            <a href="{{ $order->invoiceUrl() }}" target="_blank" class="btn btn-outline btn-sm gap-1.5"><x-icon name="file-text" size="sm" /> {{ __('Print invoice') }}</a>
                            @if ($justPlaced)
                                <a href="{{ route('catalog.index') }}" class="btn btn-primary btn-sm">{{ __('Continue shopping') }}</a>
                            @endif
                        </div>
                    </section>

                    {{-- Whoever takes the money puts a way back to the payment page
                         here: an invoice has a lifetime and customers close tabs, so
                         "pay for the order you already placed" has to be reachable
                         without placing it again. Nothing renders when the order
                         was settled by hand. --}}
                    {!! hook_filter('checkout.confirmation.payment', '', ['order' => $order]) !!}
                </div>

                <aside class="flex flex-col gap-4 min-w-0">
                    {{-- Where it has got to. --}}
                    @if ($history !== [])
                        <section class="card bg-base-100 border border-base-300/60 shadow-sm">
                            <div class="card-body p-4 sm:p-5 gap-3">
                                <h2 class="font-semibold text-base">{{ __('Order status') }}</h2>
                                <ol class="flex flex-col">
                                    @foreach ($history as $historyEntry)
                                        <li class="relative flex gap-3 pb-4 last:pb-0">
                                            @unless ($loop->last)
                                                <span class="absolute left-[5px] top-4 bottom-0 w-px bg-base-300" aria-hidden="true"></span>
                                            @endunless
                                            <span @class(['mt-1.5 w-[11px] h-[11px] rounded-full shrink-0 relative', 'bg-primary ring-4 ring-primary/20' => $loop->last, 'bg-base-content/30' => ! $loop->last])></span>
                                            <div class="min-w-0">
                                                <div @class(['text-sm', 'font-semibold' => $loop->last])>{{ $historyEntry['label'] }}</div>
                                                <time class="text-xs opacity-60" datetime="{{ $historyEntry['at']->toIso8601String() }}">{{ $historyEntry['at']->format('d.m.Y, H:i') }}</time>
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            </div>
                        </section>
                    @endif

                    {{-- Where it goes and to whom. --}}
                    <section class="card bg-base-100 border border-base-300/60 shadow-sm">
                        <div class="card-body p-4 sm:p-5 gap-3 text-sm">
                            @if ($order->hasDelivery())
                                <div>
                                    <h2 class="font-semibold text-base mb-1">{{ __('Delivery') }}</h2>
                                    @if ($order->shipping_method_name)
                                        <div>{{ $order->shipping_method_name }}</div>
                                    @endif
                                    @if ($order->address_line)
                                        <div class="opacity-70">{{ $order->address_line }}</div>
                                    @endif
                                </div>
                            @endif
                            @if ($order->customer_name || $order->phone || $order->email)
                                <div>
                                    <h2 class="font-semibold text-base mb-1">{{ __('Recipient') }}</h2>
                                    @if ($order->customer_name)<div>{{ $order->customer_name }}</div>@endif
                                    @if ($order->phone)<div class="opacity-70">{{ \App\Core\Support\Phones::display($order->phone) }}</div>@endif
                                    @if ($order->email)<div class="opacity-70 break-all">{{ $order->email }}</div>@endif
                                </div>
                            @endif
                            @if ($order->payment_method_name)
                                <div>
                                    <h2 class="font-semibold text-base mb-1">{{ __('Payment') }}</h2>
                                    <div>{{ $order->payment_method_name }}</div>
                                </div>
                            @endif
                        </div>
                    </section>

                    {{-- Whoever owns delivery says here what the customer still
                         has to do about it — where to go and when it is open, for
                         an order that is collected rather than sent. --}}
                    {!! hook_filter('checkout.confirmation.delivery', '', ['order' => $order]) !!}
                </aside>
            </div>

            {{-- Anything else this customer may do about this order, from
                 whoever owns it: asking for a return, and the conversation that
                 follows — under both columns, so on a phone the order's state
                 and delivery come before a long conversation. The page
                 authorises the reader (own account, own session, or the signed
                 link), so whatever renders here is already theirs to see. --}}
            {!! hook_filter('checkout.confirmation.extra', '', ['order' => $order]) !!}
        </div>
    </div>
@endsection
