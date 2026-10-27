{{-- The document's lines — inside the document's form, so the quantities
     and prices typed here ride its Save. Replaced whole after a line is added
     or taken off. --}}
@php
    $posted = $document->isPosted();
    $isReceipt = $document->type === 'receipt';
    $isCount = $document->type === 'count';
@endphp
<div id="document-lines" class="flex flex-col gap-2">
    @if ($lineError)
        <div class="alert alert-warning text-sm">{{ $lineError }}</div>
    @endif

    @if ($document->lines->isEmpty())
        <div class="text-center py-8 opacity-60 text-sm">{{ __('No products yet — type a SKU above.') }}</div>
    @else
        <div class="overflow-x-auto">
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>{{ __('SKU') }}</th>
                        <th>{{ __('Product') }}</th>
                        @if ($isCount && $posted)
                            <th class="text-end">{{ __('Expected') }}</th>
                        @endif
                        <th class="text-end">{{ $isCount ? __('Counted') : __('Quantity') }}</th>
                        @if ($isCount && $posted)
                            <th class="text-end">{{ __('Difference') }}</th>
                        @endif
                        @if ($isReceipt)
                            <th class="text-end">{{ __('Unit cost') }}</th>
                            <th class="text-end">{{ __('Amount') }}</th>
                        @endif
                        @unless ($posted) <th></th> @endunless
                    </tr>
                </thead>
                <tbody>
                    @foreach ($document->lines as $line)
                        <tr>
                            <td class="font-mono text-xs">{{ $line->sku }}</td>
                            <td>{{ $line->name }}</td>
                            @if ($isCount && $posted)
                                <td class="text-end tabular-nums">{{ $line->expected }}</td>
                            @endif
                            <td class="text-end">
                                @if ($posted)
                                    <span class="tabular-nums">{{ $line->qty }}</span>
                                @else
                                    <input type="number" name="lines[{{ $line->id }}][qty]" value="{{ $line->qty }}" min="0" class="input input-sm input-bordered w-24 text-end">
                                @endif
                            </td>
                            @if ($isCount && $posted)
                                @php $difference = $line->qty - (int) $line->expected; @endphp
                                <td class="text-end tabular-nums {{ $difference > 0 ? 'text-success' : ($difference < 0 ? 'text-error' : 'opacity-50') }}">{{ $difference > 0 ? '+' : '' }}{{ $difference }}</td>
                            @endif
                            @if ($isReceipt)
                                <td class="text-end whitespace-nowrap">
                                    @if ($posted)
                                        <span class="tabular-nums">{{ $line->unit_cost_original !== null ? number_format($line->unit_cost_original / 100, 2, '.', ' ').' '.$line->currency : '—' }}</span>
                                    @else
                                        <input type="text" inputmode="decimal" name="lines[{{ $line->id }}][cost]" value="{{ $line->unit_cost_original !== null ? number_format($line->unit_cost_original / 100, 2, '.', '') : '' }}" class="input input-sm input-bordered w-28 text-end" placeholder="0.00">
                                        <select name="lines[{{ $line->id }}][currency]" class="select select-sm select-bordered w-20" aria-label="{{ __('Currency') }}">
                                            @foreach ($currencies as $code)
                                                <option value="{{ $code }}" @selected(($line->currency ?: shop_currency()) === $code)>{{ $code }}</option>
                                            @endforeach
                                        </select>
                                    @endif
                                </td>
                                <td class="text-end tabular-nums whitespace-nowrap">
                                    @if ($posted && $line->unit_cost !== null)
                                        {{ admin_price($line->unit_cost * $line->qty) }}
                                    @elseif ($line->unit_cost_original !== null)
                                        {{ number_format($line->unit_cost_original * $line->qty / 100, 2, '.', ' ') }} {{ $line->currency }}
                                    @else
                                        <span class="opacity-40">—</span>
                                    @endif
                                </td>
                            @endif
                            @unless ($posted)
                                <td class="text-end">
                                    <button type="button" class="btn btn-ghost btn-sm btn-square text-error" title="{{ __('Remove') }}"
                                            hx-delete="{{ route('admin.inventory.documents.lines.remove', [$document, $line]) }}"
                                            hx-target="#document-lines" hx-swap="outerHTML" hx-params="none">
                                        <x-icon name="x" size="sm" />
                                    </button>
                                </td>
                            @endunless
                        </tr>
                    @endforeach
                </tbody>
                @if ($isReceipt && $posted && ($total = $document->total()) !== null)
                    <tfoot>
                        <tr>
                            <td colspan="4" class="text-end">{{ __('Total') }}</td>
                            <td class="text-end tabular-nums font-semibold">{{ admin_price($total) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <div class="text-xs opacity-60">{{ trans_choice(':count line|:count lines', $document->lines->count(), ['count' => $document->lines->count()]) }} · {{ __('units') }}: {{ $document->lines->sum('qty') }}</div>
    @endif
</div>
