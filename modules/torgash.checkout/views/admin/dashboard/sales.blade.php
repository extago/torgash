{{--
    Sales at a glance: three periods and the average, then one bar per day for
    thirty days. One series, so no legend — the title names it; each bar says
    its own day and numbers when pointed at. "Sales" are orders in a status
    that takes goods off the shelf (see DashboardWidgets).
--}}
@php
    $delta = $previous['revenue'] > 0 ? (int) round(($month['revenue'] - $previous['revenue']) / $previous['revenue'] * 100) : null;
    $peak = max(array_column($days, 'revenue'));
    $scale = max(1, $peak);
    $tiles = [
        [__('Today'), $today],
        [__('7 days'), $week],
        [__('30 days'), $month],
    ];
    // Zero apart: a Russian plural line cannot also carry a {0} form.
    $ordersWord = fn (int $n) => $n === 0 ? __('no orders') : trans_choice('{1} :count order|[2,*] :count orders', $n, ['count' => $n]);
@endphp
<div class="grid grid-cols-2 @xl:grid-cols-4 gap-3">
    @foreach ($tiles as [$tileLabel, $tileData])
        <div class="rounded-box bg-base-200/60 px-3 py-2">
            <div class="text-xs opacity-60">{{ $tileLabel }}</div>
            <div class="text-lg font-bold tabular-nums leading-tight">{{ admin_price($tileData['revenue']) }}</div>
            <div class="text-xs opacity-60 tabular-nums">{{ $ordersWord($tileData['orders']) }}</div>
        </div>
    @endforeach
    <div class="rounded-box bg-base-200/60 px-3 py-2">
        <div class="text-xs opacity-60">{{ __('Average order') }}</div>
        <div class="text-lg font-bold tabular-nums leading-tight">{{ admin_price($average) }}</div>
        @if ($delta !== null)
            <div @class(['text-xs tabular-nums flex items-center gap-1', 'text-success' => $delta >= 0, 'text-error' => $delta < 0])
                 title="{{ __('30 days against the 30 before them') }}">
                <x-icon :name="$delta >= 0 ? 'trending-up' : 'trending-down'" size="sm" />
                {{ ($delta > 0 ? '+' : '').$delta }}% {{ __('to the month before') }}
            </div>
        @else
            <div class="text-xs opacity-60">{{ __('nothing to compare with yet') }}</div>
        @endif
    </div>
</div>

<div>
    <div class="flex items-end gap-[2px] h-32 border-b border-base-300" role="img"
         aria-label="{{ __('Revenue per day for the last 30 days') }}">
        @foreach ($days as $day)
            <div class="group relative flex-1 h-full flex items-end">
                <div @class(['w-full rounded-t-[4px] transition-colors', 'bg-primary group-hover:bg-primary/75' => $day['revenue'] > 0, 'bg-base-300' => $day['revenue'] === 0])
                     style="height: {{ $day['revenue'] > 0 ? max(4, round($day['revenue'] / $scale * 100, 1)) : 1.5 }}%"></div>
                <div class="pointer-events-none absolute bottom-full left-1/2 -translate-x-1/2 mb-1 hidden group-hover:block z-10
                            whitespace-nowrap rounded-field bg-base-content text-base-100 text-xs px-2 py-1 shadow">
                    <div class="font-semibold">{{ $day['date']->translatedFormat('D, j M') }}</div>
                    <div class="tabular-nums">{{ admin_price($day['revenue']) }} · {{ $ordersWord($day['orders']) }}</div>
                </div>
            </div>
        @endforeach
    </div>
    <div class="flex justify-between text-xs opacity-50 mt-1 tabular-nums">
        <span>{{ $days[0]['date']->translatedFormat('j M') }}</span>
        @if ($peak > 0)
            <span>{{ __('best day :amount', ['amount' => admin_price($peak)]) }}</span>
        @endif
        <span>{{ __('today') }}</span>
    </div>
</div>

@if ($awaiting > 0)
    <a href="{{ route('admin.orders.index', ['status' => ['awaiting_payment']]) }}" class="text-xs link link-hover opacity-70">
        {{ trans_choice('{1} :count order is awaiting payment and not counted here|[2,*] :count orders are awaiting payment and not counted here', $awaiting, ['count' => $awaiting]) }}
    </a>
@endif
