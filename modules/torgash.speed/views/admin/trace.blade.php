@extends('admin.layout')

@section('title', __('Timeline').' · '.$trace->path)

@use('Torgash\Speed\Format')

@php
    $total = max(0.001, (float) $trace->total_ms);
    $parts = $trace->summary['parts'] ?? [];
    $spans = collect($trace->spans ?? [])->sortBy(fn ($s) => $s[2])->values();
    $typeNames = ['boot' => __('Engine start'), 'query' => __('Query'), 'http' => __('Outside call'), 'hook' => __('Hook')];
    // Ticks at round numbers of milliseconds, five or so across the page.
    $step = collect([1, 2, 5, 10, 20, 50, 100, 200, 500, 1000, 2000, 5000])->first(fn ($s) => $total / $s <= 6) ?? 10000;
@endphp

@section('content')
    @include('torgash.speed::admin._style')

    <div class="speed-viz flex flex-col gap-6">
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('admin.speed.route', ['r' => $trace->route]) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to the page') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <h1 class="text-lg font-bold font-mono break-all">{{ $trace->path }}</h1>
                <div class="text-xs opacity-60">
                    <span class="font-mono">{{ $trace->route }}</span> ·
                    {{ \Illuminate\Support\Carbon::parse($trace->created_at)->isoFormat('D MMM, HH:mm:ss') }} ·
                    {{ __('status') }} {{ $trace->status }}
                    @if ($trace->summary['owner'] ?? null) · {{ __('page of') }} <span class="font-mono">{{ $trace->summary['owner'] }}</span>@endif
                </div>
            </div>
            <div class="text-3xl font-bold tabular-nums {{ $total >= 1000 ? 'text-error' : '' }}">{{ Format::ms($total) }}</div>
        </div>

        <div class="card bg-base-100 border border-base-300/60 shadow-sm">
            <div class="card-body gap-3">
                @include('torgash.speed::admin._bar', ['barParts' => $parts, 'barScale' => array_sum($parts)])
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-sm">
                    @foreach (Format::PARTS as $part => $label)
                        <div>
                            <span class="speed-swatch" data-part="{{ $part }}"></span> {{ __($label) }}
                            <div class="font-semibold tabular-nums">{{ Format::ms($parts[$part] ?? 0) }}</div>
                        </div>
                    @endforeach
                </div>
                <div class="text-xs opacity-60">
                    {{ trans_choice(':count query|:count queries', $trace->summary['queries'] ?? 0) }} ·
                    {{ trans_choice(':count outside call|:count outside calls', $trace->summary['http_calls'] ?? 0) }} ·
                    {{ __('memory') }} {{ Format::bytes($trace->summary['memory'] ?? 0) }}
                </div>
            </div>
        </div>

        @if (! empty($trace->summary['repeated']))
            <div class="card bg-base-100 border border-warning/40 shadow-sm">
                <div class="card-body gap-2">
                    <h2 class="card-title text-base"><x-icon name="repeat" class="text-warning" /> {{ __('Asked again and again on this page') }}</h2>
                    <table class="table table-sm">
                        <tbody>
                            @foreach ($trace->summary['repeated'] as $q)
                                <tr class="align-top">
                                    <td class="text-right font-semibold text-warning tabular-nums">×{{ $q['count'] }}</td>
                                    <td class="text-right tabular-nums">{{ Format::ms($q['ms']) }}</td>
                                    <td class="font-mono text-xs break-all">{{ $q['caller'] ?? '—' }}</td>
                                    <td class="font-mono text-xs opacity-70 break-all">{{ \Illuminate\Support\Str::limit($q['sql'], 200) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        @if (! empty($trace->summary['modules']))
            <div class="card bg-base-100 border border-base-300/60 shadow-sm">
                <div class="card-body gap-2">
                    <h2 class="card-title text-base">{{ __('Modules on this page') }}</h2>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($trace->summary['modules'] as $module => $m)
                            <span class="badge badge-ghost gap-1 font-mono text-xs" title="{{ number_format($m['calls']) }} {{ __('calls') }} · {{ __('Of it, database') }}: {{ Format::ms($m['db_ms']) }}">
                                {{ $module }} <strong class="tabular-nums">{{ Format::ms($m['ms']) }}</strong>
                            </span>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif

        {{-- The waterfall: every span at the moment it started, as long as it
             took, on one time axis. Hovering a row gives its full text. --}}
        <div class="card bg-base-100 border border-base-300/60 shadow-sm">
            <div class="card-body gap-3">
                <div class="flex flex-wrap items-end gap-2">
                    <h2 class="card-title text-base flex-1">{{ __('Timeline') }}</h2>
                    @include('torgash.speed::admin._legend')
                </div>
                @if ($trace->summary['spans_dropped'] ?? false)
                    <p class="text-xs text-warning">{{ __('This page did more than the timeline keeps; only the first :n steps are drawn. The totals above count everything.', ['n' => \Torgash\Speed\Profile::MAX_SPANS]) }}</p>
                @endif

                <div class="text-xs">
                    <div class="flex">
                        <div class="w-2/5 shrink-0"></div>
                        <div class="flex-1 relative h-4 opacity-50">
                            @for ($t = 0; $t <= $total; $t += $step)
                                <span class="absolute -translate-x-1/2 tabular-nums" style="left: {{ $t / $total * 100 }}%">{{ Format::ms($t) }}</span>
                            @endfor
                        </div>
                    </div>

                    @forelse ($spans as $s)
                        @php
                            [$type, $label, $start, $ms, $module] = $s;
                            $part = Format::SPAN_PART[$type] ?? 'rest';
                            // The engine's own span is named in English by the Meter; the rest are SQL, URLs, hook names.
                            $label = $type === 'boot' ? __($label) : $label;
                        @endphp
                        <div class="flex items-center speed-hit rounded" title="{{ $typeNames[$type] ?? $type }} · {{ $module }} · {{ Format::ms($ms) }} {{ __('from') }} {{ Format::ms($start) }}&#10;{{ $label }}">
                            <div class="w-2/5 shrink-0 flex items-center gap-2 pr-2 py-0.5 min-w-0">
                                <span class="w-14 text-right tabular-nums shrink-0 {{ $ms >= 50 ? 'font-semibold' : 'opacity-70' }}">{{ Format::ms($ms) }}</span>
                                <span class="font-mono truncate opacity-80">{{ $label }}</span>
                            </div>
                            <div class="flex-1 relative h-4 border-l border-base-300">
                                <div class="absolute top-1 h-2 rounded-sm" data-part="{{ $part }}"
                                     style="left: {{ min(100, $start / $total * 100) }}%; width: max(2px, {{ min(100, $ms / $total * 100) }}%)"></div>
                            </div>
                        </div>
                    @empty
                        <p class="opacity-60">{{ __('Nothing was recorded on the timeline.') }}</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
@endsection
