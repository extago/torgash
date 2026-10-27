{{-- The dashboard's speed line — answered by torgash.speed through
     core.admin.dashboard. $today / $week: storefront zone summaries (or null),
     $top: the storefront page costing the most today, $grown: the page that got
     slower most this week. --}}
@use('Torgash\Speed\Format')
{{-- $bare: drawn inside core's dashboard widget frame, which carries the
     title and the link itself. --}}
@unless ($bare ?? false)
<div class="card bg-base-100 shadow-sm border border-base-300/60">
    <div class="card-body gap-3">
        <div class="flex items-center gap-2">
            <x-icon name="gauge" size="lg" class="text-primary" />
            <h2 class="card-title text-base flex-1">{{ __('Storefront speed') }}</h2>
            <a href="{{ route('admin.speed.index') }}" class="link link-primary text-sm">{{ __('Where the time goes') }} &rarr;</a>
        </div>
@endunless

        @if (! $today)
            <p class="text-sm opacity-60">
                {{ $enabled ? __('Nothing measured in the last 24 hours yet — the numbers appear as the shop is visited.') : __('Measuring is switched off.') }}
            </p>
        @else
            @php
                $change = $week && $week->p50 > 0 ? $today->p50 / $week->p50 - 1 : null;
            @endphp
            <div class="grid @xl:grid-cols-3 gap-4">
                <div>
                    <div class="text-xs opacity-60">{{ __('Typical page, last 24 hours') }}</div>
                    <div class="flex items-baseline gap-2">
                        <span class="text-2xl font-bold tabular-nums">{{ Format::ms($today->p50) }}</span>
                        @if ($change !== null && abs($change) >= 0.1)
                            <span class="text-sm {{ $change > 0 ? 'text-warning' : 'text-success' }} inline-flex items-center gap-0.5">
                                <x-icon :name="$change > 0 ? 'trending-up' : 'trending-down'" size="sm" />
                                {{ $change > 0 ? '+' : '' }}{{ round($change * 100) }}% {{ __('vs the week') }}
                            </span>
                        @endif
                    </div>
                    <div class="text-xs opacity-60">{{ __('1 in 20 slower than :ms', ['ms' => Format::ms($today->p95)]) }}</div>
                </div>
                <div>
                    <div class="text-xs opacity-60">{{ __('Costs the most') }}</div>
                    @if ($top)
                        <a href="{{ route('admin.speed.route', ['r' => $top->route]) }}" class="link link-hover font-mono text-sm break-all">{{ $top->route }}</a>
                        <div class="text-xs opacity-60">{{ Format::ms($top->p50) }} · {{ trans_choice(':count request measured|:count requests measured', $top->requests) }}</div>
                    @endif
                </div>
                <div>
                    <div class="text-xs opacity-60">{{ __('Got slower this week') }}</div>
                    @if ($grown)
                        <a href="{{ route('admin.speed.route', ['r' => $grown->route, 'period' => '30d']) }}" class="link link-hover font-mono text-sm break-all">{{ $grown->route }}</a>
                        <div class="text-xs text-warning">{{ Format::ms($grown->was_ms) }} → {{ Format::ms($grown->avg_ms) }}</div>
                    @else
                        <div class="text-sm opacity-60">{{ __('Nothing') }}</div>
                    @endif
                </div>
            </div>
        @endif
@unless ($bare ?? false)
    </div>
</div>
@endunless
