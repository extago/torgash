<!DOCTYPE html>
{{-- A stock document as a sheet (PLAN §42): the receipt a supplier signs, the
     write-off act a manager signs — read on screen like an order's invoice
     and printed by the browser's own Print, with no PDF library to keep. The
     toolbar and the sheet's frame do not print. --}}
@php
    $isReceipt = $document->type === 'receipt';
    $isCount = $document->type === 'count';
    $posted = $document->isPosted();
    $from = pick_translation($warehouses->get($document->warehouse_id)?->name);
    $to = $document->to_warehouse_id ? pick_translation($warehouses->get($document->to_warehouse_id)?->name) : null;
    $shop = \App\Models\Storefront::query()->orderByDesc('is_default')->orderBy('id')->first();
    $money = fn (?int $minor, ?string $currency) => $minor === null ? '' : number_format($minor / 100, 2, '.', ' ').' '.$currency;
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="torgash-light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $document->typeLabel() }} {{ $document->number }}</title>
    <link rel="stylesheet" href="{{ asset_url('/assets/css/app.css') }}">
    <style>
        /* On screen: the sheet on a card, light rules, colour where it helps.
           On paper: a cheap office printer drops light greys, pale text and
           background fills, so print is black on white — every rule solid
           black, nothing half-transparent, no fills, the badge as plain words. */
        .doc-lines { border-collapse: collapse; width: 100%; }
        .doc-lines th, .doc-lines td { vertical-align: top; }

        @media print {
            @page { size: A4; margin: 12mm; }
            .no-print { display: none !important; }
            .print-only { display: inline !important; }
            html, body { background: #fff !important; padding: 0 !important; }
            body, body * {
                color: #000 !important;
                opacity: 1 !important;
                background: transparent !important;
                box-shadow: none !important;
            }
            .doc-sheet { border: none !important; }
            .doc-sheet .card-body { padding: 0 !important; gap: 14px !important; }
            .doc-lines { font-size: 11pt; }
            .doc-lines th, .doc-lines td { border: 1px solid #000 !important; padding: 3pt 5pt !important; }
            .doc-lines thead th, .doc-lines tfoot td { font-weight: 700 !important; }
            .doc-lines thead { display: table-header-group; }
            .doc-lines tr { break-inside: avoid; }
            .doc-sign { border-top: 1px solid #000 !important; }
            .screen-only { display: none !important; }
        }
        .print-only { display: none; }
    </style>
    <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/actions.js') }}" defer></script>
</head>
<body class="min-h-screen bg-base-200 py-8 px-4">
    <div class="max-w-3xl mx-auto flex flex-col gap-4">
        <div class="no-print flex justify-between items-center gap-2 flex-wrap">
            <a href="{{ route('admin.inventory.documents.edit', $document) }}" class="btn btn-ghost btn-sm gap-1.5">
                <x-icon name="arrow-left" size="sm" /> {{ __('Back') }}
            </a>
            <button type="button" data-print class="btn btn-primary btn-sm gap-1.5">
                <x-icon name="printer" size="sm" /> {{ __('Print') }}
            </button>
        </div>

        @unless ($posted)
            <div class="no-print alert alert-warning text-sm">
                <x-icon name="triangle-alert" />
                <span>{{ __('Draft — not posted') }}</span>
            </div>
        @endunless

        <div class="doc-sheet card bg-base-100 shadow-sm border border-base-300/60">
            <div class="card-body gap-6">
                <div class="flex justify-between items-start gap-4">
                    <div>
                        <div class="text-xl font-bold">{{ $shop?->name ?? 'Torgash' }}</div>
                        <div class="text-sm opacity-60 flex items-center gap-1.5">
                            <x-icon :name="$document->icon()" size="sm" /> {{ $document->typeLabel() }}
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="font-mono font-bold text-lg">№ {{ $document->number }}</div>
                        <div class="text-sm opacity-60">{{ $document->document_date?->translatedFormat('j F Y') }}</div>
                        @if ($posted)
                            <div class="print-only text-sm">{{ __('Posted') }} {{ $document->posted_at?->format('d.m.Y H:i') }}</div>
                            <div class="screen-only badge badge-success badge-sm mt-1">{{ __('Posted') }} {{ $document->posted_at?->format('d.m.Y H:i') }}</div>
                        @else
                            <div class="print-only text-sm font-bold">{{ __('Draft — not posted') }}</div>
                            <div class="screen-only badge badge-warning badge-sm mt-1">{{ __('Draft — not posted') }}</div>
                        @endif
                    </div>
                </div>

                <div class="grid sm:grid-cols-2 gap-4 text-sm">
                    <div>
                        <div class="text-xs uppercase tracking-wide opacity-50 mb-1">{{ $document->type === 'transfer' ? __('From warehouse') : __('Warehouse') }}</div>
                        <div class="font-semibold">{{ $from }}</div>
                        @if ($to)
                            <div class="text-xs uppercase tracking-wide opacity-50 mt-3 mb-1">{{ __('To warehouse') }}</div>
                            <div class="font-semibold">{{ $to }}</div>
                        @endif
                    </div>
                    <div>
                        @if ($document->counterparty)
                            <div class="text-xs uppercase tracking-wide opacity-50 mb-1">{{ $isReceipt ? __('Supplier') : __('Reason') }}</div>
                            <div class="font-semibold">{{ $document->counterparty }}</div>
                        @endif
                        @if ($document->user_name || $document->posted_by_name)
                            <div class="text-xs uppercase tracking-wide opacity-50 mt-3 mb-1">{{ __('Made by') }}</div>
                            <div>{{ $document->posted_by_name ?: $document->user_name }}</div>
                        @endif
                    </div>
                </div>

                @if ($document->note)
                    <div class="text-sm rounded-box bg-base-200/60 px-4 py-3 whitespace-pre-line">{{ $document->note }}</div>
                @endif

                @php
                    $amounts = collect($values)->pluck('amount')->filter(fn ($a) => $a !== null);
                    $unpriced = (int) collect($values)->sum('unknown');
                    $estimate = collect($values)->contains('estimate', true);
                    $sheetCurrency = collect($values)->pluck('currency')->unique();
                    $sheetTotal = $sheetCurrency->count() === 1 ? (int) $amounts->sum() : null;
                    $costTitle = $isReceipt ? __('Unit cost') : ($isCount ? __('Cost of the difference') : __('Cost per unit'));
                @endphp
                <div class="overflow-x-auto">
                    <table class="doc-lines w-full text-sm border-collapse">
                        <thead>
                            <tr class="bg-base-200">
                                <th class="border border-base-300 px-2 py-1.5 w-8 text-end">№</th>
                                <th class="border border-base-300 px-2 py-1.5 text-start">{{ __('SKU') }}</th>
                                <th class="border border-base-300 px-2 py-1.5 text-start">{{ __('Product') }}</th>
                                @if ($isCount)
                                    <th class="border border-base-300 px-2 py-1.5 text-end">{{ __('Expected') }}</th>
                                    <th class="border border-base-300 px-2 py-1.5 text-end">{{ __('Counted') }}</th>
                                    <th class="border border-base-300 px-2 py-1.5 text-end">{{ __('Difference') }}</th>
                                @else
                                    <th class="border border-base-300 px-2 py-1.5 text-end">{{ __('Quantity') }}</th>
                                @endif
                                <th class="border border-base-300 px-2 py-1.5 text-center">{{ __('Unit of measure') }}</th>
                                <th class="border border-base-300 px-2 py-1.5 text-end">{{ $costTitle }}</th>
                                <th class="border border-base-300 px-2 py-1.5 text-end">{{ __('Amount') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($document->lines as $i => $line)
                                @php
                                    $difference = $isCount && $line->expected !== null ? $line->qty - $line->expected : null;
                                    $value = $values[$line->id] ?? ['unit' => null, 'amount' => null, 'unknown' => 0, 'currency' => shop_currency()];
                                @endphp
                                <tr>
                                    <td class="border border-base-300 px-2 py-1.5 text-end tabular-nums opacity-60">{{ $i + 1 }}</td>
                                    <td class="border border-base-300 px-2 py-1.5 font-mono text-xs whitespace-nowrap">{{ $line->sku }}</td>
                                    <td class="border border-base-300 px-2 py-1.5">{{ $line->name }}</td>
                                    @if ($isCount)
                                        <td class="border border-base-300 px-2 py-1.5 text-end tabular-nums">{{ $line->expected ?? '—' }}</td>
                                        <td class="border border-base-300 px-2 py-1.5 text-end tabular-nums font-semibold">{{ $line->qty }}</td>
                                        <td class="border border-base-300 px-2 py-1.5 text-end tabular-nums {{ $difference > 0 ? 'text-success' : ($difference < 0 ? 'text-error' : 'opacity-50') }}">{{ $difference === null ? '—' : ($difference > 0 ? '+' : '').$difference }}</td>
                                    @else
                                        <td class="border border-base-300 px-2 py-1.5 text-end tabular-nums font-semibold">{{ $line->qty }}</td>
                                    @endif
                                    <td class="border border-base-300 px-2 py-1.5 text-center opacity-70">{{ __('pcs') }}</td>
                                    <td class="border border-base-300 px-2 py-1.5 text-end tabular-nums whitespace-nowrap">{{ $value['unit'] === null ? '—' : $money($value['unit'], $value['currency']) }}</td>
                                    <td class="border border-base-300 px-2 py-1.5 text-end tabular-nums whitespace-nowrap">
                                        {{ $value['amount'] === null ? '—' : $money($value['amount'], $value['currency']) }}
                                        @if ($value['unknown'] > 0 && $value['amount'] !== null)
                                            <span class="text-warning">*</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="bg-base-200 font-semibold">
                                <td class="border border-base-300 px-2 py-1.5 text-end" colspan="3">{{ __('Total') }}</td>
                                @if ($isCount)
                                    <td class="border border-base-300 px-2 py-1.5 text-end tabular-nums">{{ $document->lines->sum('expected') }}</td>
                                    <td class="border border-base-300 px-2 py-1.5 text-end tabular-nums">{{ $document->lines->sum('qty') }}</td>
                                    <td class="border border-base-300 px-2 py-1.5"></td>
                                @else
                                    <td class="border border-base-300 px-2 py-1.5 text-end tabular-nums">{{ $document->lines->sum('qty') }}</td>
                                @endif
                                <td class="border border-base-300 px-2 py-1.5"></td>
                                <td class="border border-base-300 px-2 py-1.5"></td>
                                <td class="border border-base-300 px-2 py-1.5 text-end tabular-nums whitespace-nowrap">{{ $sheetTotal !== null && $amounts->isNotEmpty() ? $money($sheetTotal, $sheetCurrency->first()) : '—' }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="text-xs opacity-60 flex flex-col gap-0.5">
                    <span>{{ trans_choice(':count line|:count lines', $document->lines->count(), ['count' => $document->lines->count()]) }}, {{ __('units') }}: {{ $document->lines->sum('qty') }}.</span>
                    @if ($estimate)
                        <span>{{ __('The cost is an estimate: what the oldest batches would give now. Posting writes the exact figure.') }}</span>
                    @endif
                    @if ($unpriced > 0)
                        <span class="text-warning">* {{ trans_choice(':count unit without a price|:count units without a price', $unpriced, ['count' => $unpriced]) }}</span>
                    @endif
                </div>

                <div class="grid grid-cols-2 gap-10 pt-10 text-sm">
                    <div>
                        <div class="doc-sign border-t border-base-content/40 pt-1 opacity-70">{{ $isReceipt ? __('Handed over') : __('Made by') }}</div>
                    </div>
                    <div>
                        <div class="doc-sign border-t border-base-content/40 pt-1 opacity-70">{{ $isReceipt ? __('Received') : __('Approved') }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
