@extends('layouts.storefront')

@section('title', __('Orders').' · '.current_storefront()?->name)

@section('content')
    <div class="flex flex-col md:flex-row gap-6">
        @include('account._nav')

        <div class="flex-1 min-w-0">
            <h1 class="text-xl font-bold mb-4">{{ __('Orders') }}</h1>

            @if ($orders->isEmpty())
                <div class="card bg-base-100 shadow-sm border border-base-300/60">
                    <div class="card-body text-center opacity-60 py-10">
                        {{ __("You haven't placed any orders yet.") }}
                        <a href="{{ route('catalog.index') }}" class="link link-primary">{{ __('Start shopping') }}</a>
                    </div>
                </div>
            @else
                {{-- Not a table: five columns did not fit a phone (the number
                     broke in two, the total and the buttons went off the
                     screen). A card per order on a phone; on a computer the
                     cards join into one panel of rows (number, state,
                     pictures, total) — a card each left a wide screen empty.
                     The same shape as the enquiries list: the whole row opens
                     the order (the number's link is stretched over it), and
                     the invoice is the one button laid above that link. --}}
                <div class="flex flex-col gap-3 md:gap-0 md:bg-base-100 md:rounded-box md:border md:border-base-300/60 md:shadow-sm md:divide-y md:divide-base-300/60">
                    @foreach ($orders as $order)
                        @php
                            $orderLines = collect(\Torgash\Checkout\Orders\OrderLines::tree($order->items ?? []))->pluck('line');
                            $orderShown = $orderLines->take(3);
                        @endphp
                        <article class="group relative bg-base-100 rounded-box border border-base-300/60 shadow-sm md:rounded-none md:border-0 md:shadow-none md:bg-transparent hover:bg-base-200/60 transition-colors p-4 md:px-5 md:py-3.5 flex flex-col md:flex-row md:items-center gap-3 md:gap-5">
                            <div class="flex items-start justify-between gap-3 md:contents">
                                <div class="min-w-0 md:w-36 shrink-0">
                                    <a href="{{ route('checkout.confirmation', $order->order_number) }}"
                                       class="font-medium whitespace-nowrap tabular-nums after:absolute after:inset-0 after:z-1 after:content-[''] focus-visible:outline-none focus-visible:after:ring-2 focus-visible:after:ring-primary focus-visible:after:rounded-box">
                                        <span class="opacity-50">№</span> {{ $order->order_number }}
                                    </a>
                                    <time class="block text-xs opacity-60 mt-0.5" datetime="{{ $order->created_at->toIso8601String() }}">{{ $order->created_at->format('d.m.Y, H:i') }}</time>
                                </div>
                                <div class="text-sm md:w-40 shrink-0">@include('torgash.checkout::partials.order-state', ['order' => $order])</div>
                            </div>

                            {{-- A merged order keeps no lines of its own: no empty strip. --}}
                            <div @class(['flex items-center gap-2 md:flex-1 min-w-0', 'hidden md:flex' => $orderLines->isEmpty()])>
                                @foreach ($orderShown as $orderLine)
                                    @php $orderPicture = $pictures[$orderLine['product_id'] ?? 0] ?? null; @endphp
                                    <span class="w-12 h-12 md:w-10 md:h-10 rounded-field border border-base-300/70 bg-base-100 overflow-hidden grid place-items-center shrink-0" title="{{ $order->itemLabel($orderLine) }} × {{ $orderLine['qty'] ?? 1 }}">
                                        @if ($orderPicture)
                                            <img src="{{ $orderPicture }}" alt="{{ $order->itemLabel($orderLine) }}" class="w-full h-full object-contain" loading="lazy">
                                        @else
                                            <x-icon name="package" size="sm" class="opacity-30" />
                                        @endif
                                    </span>
                                @endforeach
                                @if ($orderLines->count() > $orderShown->count())
                                    <span class="w-12 h-12 md:w-10 md:h-10 rounded-field bg-base-200 grid place-items-center text-sm font-medium opacity-70 shrink-0">+{{ $orderLines->count() - $orderShown->count() }}</span>
                                @endif
                                @if ($orderLines->count() === 1)
                                    <span class="text-sm opacity-80 min-w-0 line-clamp-2">{{ $order->itemLabel($orderLines->first()) }} <span class="opacity-60 whitespace-nowrap">× {{ $orderLines->first()['qty'] ?? 1 }}</span></span>
                                @endif
                            </div>

                            <div class="flex items-center justify-between gap-4 md:shrink-0">
                                {{-- The order's own currency at the rate frozen onto it, never
                                     format_price(): that converts at TODAY's rate, so switching
                                     the header used to change what a year-old order cost. --}}
                                <div class="md:text-right md:w-28">
                                    <span class="text-sm opacity-60 md:hidden">{{ __('Total') }}:</span>
                                    <span class="font-semibold whitespace-nowrap tabular-nums">{{ $order->money($order->total) }}</span>
                                    @if ($order->chargeCurrency() !== current_currency())
                                        <div class="text-xs opacity-50">{{ __('About :amount today', ['amount' => format_price($order->total)]) }}</div>
                                    @endif
                                </div>
                                <div class="flex items-center gap-2">
                                    <a href="{{ route('checkout.invoice', $order->order_number) }}" target="_blank"
                                       class="relative z-10 btn btn-ghost btn-sm btn-square opacity-60 hover:opacity-100"
                                       title="{{ __('Invoice') }}" aria-label="{{ __('Invoice') }} {{ $order->order_number }}"><x-icon name="file-text" size="sm" /></a>
                                    <x-icon name="chevron-right" size="sm" class="hidden md:block opacity-30 group-hover:opacity-70 shrink-0" />
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
@endsection
