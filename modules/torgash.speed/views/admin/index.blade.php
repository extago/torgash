@extends('admin.layout')

@section('title', __('Speed'))

@use('Torgash\Speed\Format')

@php
    $zoneNames = ['storefront' => __('Storefront'), 'admin' => __('Admin'), 'api' => 'API'];
    $periodNames = ['24h' => __('24 hours'), '7d' => __('7 days'), '30d' => __('30 days')];
    $scale = max(1, (float) $routes->max(fn ($r) => array_sum($r->parts)));
@endphp

@section('content')
    @include('torgash.speed::admin._style')

    <div class="speed-viz flex flex-col gap-6">
        @if (session('status'))
            <div class="alert alert-success">{{ session('status') }}</div>
        @endif

        {{-- Header: what is being looked at, and the one action. --}}
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-bold flex-1">{{ __('Speed') }}</h1>

            <div class="join">
                @foreach ($periodNames as $key => $label)
                    <a href="{{ route('admin.speed.index', array_filter(['period' => $key, 'zone' => $zone])) }}"
                       class="btn btn-sm join-item {{ $period === $key ? 'btn-primary' : '' }}">{{ $label }}</a>
                @endforeach
            </div>

            @if ($tracingUntil)
                <form method="POST" action="{{ route('admin.speed.trace-me.stop') }}">
                    @csrf
                    <button class="btn btn-sm btn-warning gap-2">
                        <x-icon name="radio" /> {{ __('Tracing your pages until :time', ['time' => date('H:i', $tracingUntil)]) }} · {{ __('Stop') }}
                    </button>
                </form>
            @else
                <form method="POST" action="{{ route('admin.speed.trace-me') }}">
                    @csrf
                    <button class="btn btn-sm btn-outline gap-2" title="{{ __('For ten minutes every page you open is measured and keeps its full timeline.') }}">
                        <x-icon name="scan-search" /> {{ __('Trace my pages for 10 minutes') }}
                    </button>
                </form>
            @endif
        </div>

        @unless ($enabled)
            <div class="alert alert-warning">{{ __('Measuring is switched off in this module\'s settings — nothing new is being recorded.') }}</div>
        @endunless

        {{-- Where the shop keeps what it has already worked out: core's
             answer (App\Core\Performance\CacheSetup), changed on core's screen. --}}
        @php
            $cacheSetup = app(\App\Core\Performance\CacheSetup::class)->current();
            $placeNames = ['file' => __('a folder on this server'), 'database' => __('the database'), 'redis' => 'Redis'];
        @endphp
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm opacity-80">
            <span class="flex items-center gap-1.5"><x-icon name="database-zap" size="sm" /> {{ __('Cache') }}: <b>{{ $placeNames[$cacheSetup['cache']] ?? $cacheSetup['cache'] }}</b></span>
            <span>{{ __('Sessions') }}: <b>{{ $placeNames[$cacheSetup['sessions']] ?? $cacheSetup['sessions'] }}</b></span>
            @can('cache.manage')
                <a href="{{ route('admin.cache') }}" class="link link-primary">{{ __('Change') }} →</a>
            @endcan
        </div>

        {{-- One tile per audience: the numbers a person feels. --}}
        <div class="grid sm:grid-cols-3 gap-4">
            @foreach ($zoneNames as $key => $label)
                @php $z = $zones[$key] ?? null; @endphp
                <a href="{{ route('admin.speed.index', ['period' => $period, 'zone' => $zone === $key ? null : $key]) }}"
                   class="card bg-base-100 border {{ $zone === $key ? 'border-primary' : 'border-base-300/60' }} shadow-sm hover:border-primary/60">
                    <div class="card-body p-4 gap-1">
                        <div class="text-xs uppercase tracking-wide opacity-60">{{ $label }}</div>
                        @if ($z)
                            <div class="flex items-baseline gap-2">
                                <span class="text-2xl font-bold">{{ Format::ms($z->p50) }}</span>
                                <span class="text-sm opacity-60">{{ __('typical') }}</span>
                            </div>
                            <div class="text-sm opacity-80">
                                {{ __('1 in 20 slower than :ms', ['ms' => Format::ms($z->p95)]) }}
                            </div>
                            <div class="text-xs opacity-50">{{ trans_choice(':count request measured|:count requests measured', $z->requests) }}</div>
                        @else
                            <div class="text-sm opacity-50 py-2">{{ __('Nothing measured yet') }}</div>
                        @endif
                    </div>
                </a>
            @endforeach
        </div>

        {{-- Where the time goes. --}}
        <div class="card bg-base-100 border border-base-300/60 shadow-sm">
            <div class="card-body gap-3">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1">
                        <h2 class="card-title text-base">{{ __('Where the time goes') }}</h2>
                        <p class="text-xs opacity-60">{{ __('Pages by the time they cost the shop in total — a page opened often at 80 ms costs more than a report opened twice at three seconds. The bar is one typical visit, split into what it was spent on.') }}</p>
                    </div>
                    @include('torgash.speed::admin._legend')
                </div>

                @if ($routes->isEmpty())
                    <p class="text-sm opacity-60">{{ __('No pages measured in this period yet. Open a few pages of the shop — or press "Trace my pages" and do it.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>{{ __('Page') }}</th>
                                    <th class="text-right">{{ __('Share') }}</th>
                                    <th class="text-right">{{ __('Visits') }}</th>
                                    <th class="text-right">{{ __('Typical') }}</th>
                                    <th class="text-right">{{ __('1 in 20') }}</th>
                                    <th class="text-right">{{ __('Queries') }}</th>
                                    <th class="w-[35%] min-w-48">{{ __('One visit') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($routes as $r)
                                    <tr class="speed-hit">
                                        <td class="max-w-xs">
                                            <a href="{{ route('admin.speed.route', ['r' => $r->route, 'period' => $period === '24h' ? '7d' : $period]) }}" class="link link-hover font-mono text-xs break-all">{{ $r->route }}</a>
                                            <div class="text-xs opacity-50">{{ $zoneNames[$r->zone] ?? $r->zone }}@if ($r->errors > 0) · <span class="text-error">{{ trans_choice(':count error|:count errors', $r->errors) }}</span>@endif</div>
                                        </td>
                                        <td class="text-right tabular-nums">{{ Format::percent($routesTotal > 0 ? $r->total_ms / $routesTotal : 0) }}</td>
                                        <td class="text-right tabular-nums">{{ number_format($r->requests) }}</td>
                                        <td class="text-right tabular-nums">{{ Format::ms($r->p50) }}</td>
                                        <td class="text-right tabular-nums {{ $r->p95 >= 1000 ? 'text-error font-semibold' : '' }}">{{ Format::ms($r->p95) }}</td>
                                        <td class="text-right tabular-nums {{ $r->queries_per_request >= 60 ? 'text-warning font-semibold' : '' }}">{{ number_format($r->queries_per_request, $r->queries_per_request < 10 ? 1 : 0) }}</td>
                                        <td>@include('torgash.speed::admin._bar', ['barParts' => $r->parts, 'barScale' => $scale])</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        @if ($grown->isNotEmpty())
            <div class="card bg-base-100 border border-warning/40 shadow-sm">
                <div class="card-body gap-2">
                    <h2 class="card-title text-base"><x-icon name="trending-up" class="text-warning" /> {{ __('Got slower this week') }}</h2>
                    <p class="text-xs opacity-60">{{ __('Average of the last seven days against the seven before, for pages visited often enough in both to say.') }}</p>
                    <ul class="text-sm divide-y divide-base-300/60">
                        @foreach ($grown as $g)
                            <li class="py-1.5 flex flex-wrap gap-x-3">
                                <a href="{{ route('admin.speed.route', ['r' => $g->route, 'period' => '30d']) }}" class="link link-hover font-mono text-xs flex-1">{{ $g->route }}</a>
                                <span class="tabular-nums">{{ Format::ms($g->was_ms) }} → <strong>{{ Format::ms($g->avg_ms) }}</strong></span>
                                <span class="text-warning tabular-nums">+{{ round($g->growth * 100) }}%</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        @endif

        <div class="grid xl:grid-cols-2 gap-6">
            @include('torgash.speed::admin._modules', ['speedModules' => $modules])
            @include('torgash.speed::admin._traces', ['speedTraces' => $traces])
        </div>

        @include('torgash.speed::admin._repeated', ['speedRepeated' => $repeated])
        @include('torgash.speed::admin._queries', ['speedQueries' => $queries])

        <p class="text-xs opacity-50">
            {{ __('Measuring :percent% of requests. Every number here is from real visits to this shop, added up by the hour; "typical" is the median, "1 in 20" the 95th percentile, both read from a histogram and accurate to its bucket.', ['percent' => $percent]) }}
        </p>
    </div>
@endsection
