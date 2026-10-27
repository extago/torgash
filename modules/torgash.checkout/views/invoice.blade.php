<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Invoice') }} {{ $order->order_number }}</title>
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
            .invoice-sheet { box-shadow: none !important; border: none !important; }
        }
    </style>
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/actions.js') }}" defer></script>
</head>
@php
    // The shop that sold it — the order's own storefront, not whichever one
    // the reader happens to be on (the admin has none of its own).
    $invoiceShop = ($storefront ?? null) ?? \App\Models\Storefront::find($order->storefront_id) ?? current_storefront();
    $invoiceStatus = \Torgash\Checkout\Models\OrderStatus::find($order->status);
@endphp
<body class="min-h-screen bg-base-200 py-8">
    <div class="max-w-2xl mx-auto flex flex-col gap-4">
        <div class="no-print flex justify-between items-center gap-2 flex-wrap">
            <a href="{{ $backUrl }}" class="btn btn-ghost btn-sm">&larr; {{ __('Back') }}</a>
            <div class="flex items-center gap-2">
                {{-- The admin's switch: which language the DOCUMENT is in. Named
                     by each language in itself, so it reads whatever the sheet
                     below is in. Never printed. --}}
                @if (count($invoiceLanguages ?? []) > 1)
                    @php $invoiceNative = \App\Models\Language::whereIn('code', $invoiceLanguages)->pluck('native_name', 'code'); @endphp
                    <div class="join" role="group" aria-label="Language">
                        @foreach ($invoiceLanguages as $invoiceLang)
                            <a href="{{ request()->fullUrlWithQuery(['lang' => $invoiceLang]) }}"
                               @class(['btn btn-sm join-item', 'btn-active' => $invoiceLang === $invoiceLanguage])>
                                {{ $invoiceNative[$invoiceLang] ?? strtoupper($invoiceLang) }}
                            </a>
                        @endforeach
                    </div>
                @endif
                <button type="button" data-print class="btn btn-primary btn-sm">{{ __('Print') }}</button>
            </div>
        </div>

        <div class="invoice-sheet card bg-base-100 shadow-sm border border-base-300/60">
            <div class="card-body gap-6">
                <div class="flex justify-between items-start">
                    <div>
                        <div class="text-xl font-bold">{{ $invoiceShop?->name ?? 'Torgash' }}</div>
                        <div class="text-sm opacity-60">{{ __('Invoice') }}</div>
                    </div>
                    <div class="text-right">
                        <div class="font-mono font-bold">{{ $order->order_number }}</div>
                        {{-- Written the way the document's language writes a date. --}}
                        <div class="text-sm opacity-60">{{ $order->created_at->translatedFormat('j F Y') }}</div>
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <div class="text-xs uppercase tracking-wide opacity-50 mb-1">{{ __('Bill to') }}</div>
                        @if ($order->company_name)
                            <div class="font-semibold">{{ $order->company_name }}</div>
                            @if ($order->company_tax_id)
                                <div class="opacity-70">{{ __('Tax code') }}: {{ $order->company_tax_id }}</div>
                            @endif
                        @endif
                        <div>{{ $order->customer_name }}</div>
                        <div class="opacity-70">{{ $order->email }}</div>
                        @if ($order->phone)<div class="opacity-70">{{ $order->phone }}</div>@endif
                        {{-- Joined from the parts that are there: an order bought in
                             one click has no address, and bare commas are not one. --}}
                        @php
                            $invoiceAddress = collect([
                                trim((string) $order->address_line),
                                trim(implode(' ', array_filter([$order->city, $order->postal_code]))),
                                $order->countryName(),
                            ])->filter()->implode(', ');
                        @endphp
                        @if ($invoiceAddress !== '')
                            <div class="opacity-70">{{ $invoiceAddress }}</div>
                        @endif
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-wide opacity-50 mb-1">{{ __('Order details') }}</div>
                        <div><span class="opacity-60">{{ __('Status') }}:</span> {{ $invoiceStatus?->t('name') ?? $order->status }}</div>
                        @if ($order->hasDelivery())
                            <div><span class="opacity-60">{{ __('Shipping') }}:</span> {{ $order->shipping_method_name ?? '—' }}</div>
                        @endif
                        <div><span class="opacity-60">{{ __('Payment') }}:</span> {{ $order->payment_method_name ?? '—' }}</div>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>{{ __('SKU') }}</th>
                                <th>{{ __('Product') }}</th>
                                <th class="text-right">{{ __('Qty') }}</th>
                                <th class="text-right">{{ __('Unit price') }}</th>
                                <th class="text-right">{{ __('Total') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach (\Torgash\Checkout\Orders\OrderLines::tree($order->items ?? []) as $node)
                                @foreach ([$node['line'], ...$node['children']] as $itemIndex => $item)
                                    <tr>
                                        <td class="font-mono text-xs">{{ $item['sku'] }}</td>
                                        <td @class(['ps-5' => $itemIndex > 0])>
                                            @if ($itemIndex > 0)<span class="opacity-40">↳</span>@endif
                                            {{ $item['name'] }}
                                            {{-- Inside this line's own price: a warranty,
                                                 an engraving, a service charged with it. --}}
                                            @foreach ($item['options'] ?? [] as $option)
                                                <div class="text-xs opacity-60">{{ ($option['amount'] ?? 0) < 0 ? '−' : '+' }} {{ $option['label'] }}@if (($option['qty'] ?? 1) > 1) ×{{ $option['qty'] }}@endif</div>
                                            @endforeach
                                        </td>
                                        <td class="text-right">{{ $item['qty'] }}</td>
                                        <td class="text-right">{{ $order->money($item['unit_price']) }}</td>
                                        <td class="text-right font-medium">{{ $order->money($item['line_total']) }}</td>
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="flex flex-col items-end gap-1 text-sm">
                    <div class="flex justify-between w-56"><span class="opacity-60">{{ __('Subtotal') }}</span><span>{{ $order->money($order->subtotal) }}</span></div>
                    @foreach ($order->discountLines() as $discountLine)
                        <div class="flex justify-between w-56 text-success">
                            <span>{{ $discountLine['label'] }}</span>
                            <span>&minus;{{ $order->money($discountLine['amount']) }}</span>
                        </div>
                    @endforeach
                    @if ($order->hasDelivery())
                        <div class="flex justify-between w-56"><span class="opacity-60">{{ __('Shipping') }}</span><span>{{ $order->deliveryPending() ? __('to be confirmed') : ($order->shipping_cost > 0 ? $order->money($order->shipping_cost) : __('Free')) }}</span></div>
                    @endif
                    @foreach (hook_filter('checkout.order.extra_totals', [], ['order' => $order]) as $line)
                        <div class="flex justify-between w-56 {{ $line['included'] ? 'opacity-60' : '' }}">
                            <span class="opacity-60">{{ $line['label'] }}</span><span>{{ $order->money($line['amount']) }}</span>
                        </div>
                    @endforeach
                    <div class="w-56 border-t border-base-300 my-1"></div>
                    <div class="flex justify-between w-56 font-bold text-lg"><span>{{ __('Total') }}</span><span>{{ $order->money($order->total) }}</span></div>
                    @include('torgash.checkout::partials.prepaid-lines', ['order' => $order, 'prepaidMoney' => fn (int $a) => $order->money($a), 'prepaidWidth' => 'w-56'])
                    {{-- An invoice has to state what will actually be taken: the
                         order is in the currency it was agreed in, the bank
                         charges in the one it holds an account in. --}}
                    <div class="w-56 text-right">
                        @include('torgash.checkout::partials.payment-note', ['order' => $order])
                    </div>
                </div>

                @if ($order->notes)
                    <div class="text-sm opacity-70 border-t border-base-300 pt-3">
                        <span class="opacity-50">{{ __('Notes') }}:</span> &ldquo;{{ $order->notes }}&rdquo;
                    </div>
                @endif
            </div>
        </div>
    </div>
</body>
</html>
