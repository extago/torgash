{{-- Query shapes by total time. $speedQueries from Reports::queries(). --}}
<div class="card bg-base-100 border border-base-300/60 shadow-sm">
    <div class="card-body gap-3">
        <div>
            <h2 class="card-title text-base">{{ __('The most expensive queries') }}</h2>
            <p class="text-xs opacity-60">{{ __('Query shapes by the database time they took in total. One slow query shows as a high "slowest"; a cheap one asked everywhere shows as many runs.') }}</p>
        </div>

        @if ($speedQueries->isEmpty())
            <p class="text-sm opacity-60">{{ __('No queries measured yet.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th class="text-right">{{ __('In total') }}</th>
                            <th class="text-right">{{ __('Runs') }}</th>
                            <th class="text-right">{{ __('Per page') }}</th>
                            <th class="text-right">{{ __('Slowest') }}</th>
                            <th>{{ __('Query') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($speedQueries as $q)
                            <tr class="align-top">
                                <td class="text-right tabular-nums font-semibold">{{ \Torgash\Speed\Format::ms($q->total_ms) }}</td>
                                <td class="text-right tabular-nums">{{ number_format($q->executions) }}</td>
                                <td class="text-right tabular-nums {{ $q->max_repeat >= 5 ? 'text-warning' : '' }}">{{ number_format($q->per_request, 1) }}</td>
                                <td class="text-right tabular-nums {{ $q->max_ms >= 100 ? 'text-error' : '' }}">{{ \Torgash\Speed\Format::ms($q->max_ms) }}</td>
                                <td class="max-w-xl">
                                    <div class="font-mono text-xs break-all">{{ \Illuminate\Support\Str::limit($q->sql, 260) }}</div>
                                    <div class="text-xs opacity-50 mt-0.5 break-all">
                                        @if ($q->caller){{ $q->caller }} · @endif{{ \Illuminate\Support\Str::limit($q->routes ?? '', 120) }}
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
