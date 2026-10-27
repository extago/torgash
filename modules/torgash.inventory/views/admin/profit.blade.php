@extends('admin.layout')

@section('title', __('Profit'))

@section('content')
    <div class="card bg-base-100 border border-base-300">
        <div class="card-body gap-4">
            <p class="text-sm opacity-70 max-w-3xl">
                {{ __('The sales of a period — orders in a status that takes goods off the shelf: the goods after discounts and the delivery charged, less what the goods cost from their receipts, less the order’s own expenses, plus its extra income. Taxes are not taken off; units that arrived without a price are counted, not costed at zero.') }}
            </p>

            <form method="GET" class="flex flex-wrap items-end gap-2">
                <input type="date" name="from" value="{{ $from }}" class="input input-sm input-bordered" aria-label="{{ __('From') }}">
                <input type="date" name="to" value="{{ $to }}" class="input input-sm input-bordered" aria-label="{{ __('To') }}">
                <button class="btn btn-sm">{{ __('Show') }}</button>
            </form>

            <div class="stats stats-vertical sm:stats-horizontal border border-base-300">
                <div class="stat">
                    <div class="stat-title">{{ __('Sales') }}</div>
                    <div class="stat-value text-2xl tabular-nums">{{ admin_price($totals['goods'] + $totals['delivery']) }}</div>
                    <div class="stat-desc">{{ trans_choice(':count order|:count orders', $totals['orders'], ['count' => $totals['orders']]) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-title">{{ __('Cost of the goods') }}</div>
                    <div class="stat-value text-2xl tabular-nums">{{ admin_price($totals['cost']) }}</div>
                    @if ($totals['unknown'] > 0)
                        <div class="stat-desc text-warning">{{ trans_choice(':count unit without a price|:count units without a price', $totals['unknown'], ['count' => $totals['unknown']]) }}</div>
                    @endif
                </div>
                <div class="stat">
                    <div class="stat-title">{{ __('Expenses and income') }}</div>
                    <div class="stat-value text-2xl tabular-nums">{{ admin_price($totals['income'] - $totals['expenses']) }}</div>
                </div>
                <div class="stat">
                    <div class="stat-title">{{ __('Profit') }}</div>
                    <div class="stat-value text-2xl tabular-nums {{ $totals['profit'] < 0 ? 'text-error' : 'text-success' }}">{{ admin_price($totals['profit']) }}</div>
                </div>
            </div>

            @if ($byLabel->isNotEmpty())
                <div class="flex flex-wrap gap-2 text-sm">
                    @foreach ($byLabel as $row)
                        <span class="badge badge-lg gap-1.5 {{ $row->kind === 'income' ? 'badge-success badge-outline' : 'badge-ghost' }}">
                            {{ $row->label }} <span class="tabular-nums">{{ $row->kind === 'income' ? '+' : '−' }}{{ admin_price((int) $row->amount) }}</span> <span class="opacity-50">×{{ $row->n }}</span>
                        </span>
                    @endforeach
                </div>
            @endif

            @if ($orders->isEmpty())
                <div class="text-center py-12 opacity-60">{{ __('No sales in these days.') }}</div>
            @else
                <div class="overflow-x-auto">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th>{{ __('Order') }}</th>
                                <th>{{ __('Date') }}</th>
                                <th class="text-end">{{ __('Goods') }}</th>
                                <th class="text-end">{{ __('Delivery') }}</th>
                                <th class="text-end">{{ __('Cost of the goods') }}</th>
                                <th class="text-end">{{ __('Expenses') }}</th>
                                <th class="text-end">{{ __('Income') }}</th>
                                <th class="text-end">{{ __('Profit') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($orders as $order)
                                @php $line = $lines[$order->id] ?? null; @endphp
                                @continue(! $line)
                                <tr>
                                    <td><a href="{{ url('/admin/orders/'.$order->order_number) }}" class="link font-mono">{{ $order->order_number }}</a></td>
                                    <td class="whitespace-nowrap opacity-60">{{ \Illuminate\Support\Carbon::parse($order->created_at)->format('d.m.Y') }}</td>
                                    <td class="text-end tabular-nums">{{ admin_price($line['goods']) }}</td>
                                    <td class="text-end tabular-nums">{{ admin_price($line['delivery']) }}</td>
                                    <td class="text-end tabular-nums">
                                        {{ admin_price($line['cost']) }}
                                        @if ($line['unknown'] > 0)
                                            <span class="text-warning" title="{{ trans_choice(':count unit without a price|:count units without a price', $line['unknown'], ['count' => $line['unknown']]) }}">*</span>
                                        @endif
                                    </td>
                                    <td class="text-end tabular-nums">{{ $line['expenses'] ? admin_price($line['expenses']) : '' }}</td>
                                    <td class="text-end tabular-nums">{{ $line['income'] ? admin_price($line['income']) : '' }}</td>
                                    <td class="text-end tabular-nums font-semibold {{ $line['profit'] < 0 ? 'text-error' : '' }}">{{ admin_price($line['profit']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                {{ $orders->links() }}
            @endif
        </div>
    </div>
@endsection
