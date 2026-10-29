@extends('admin.layout')

@section('title', __('Speed').' · '.$route)

@use('Torgash\Speed\Format')
@use('Torgash\Speed\Recorder')

@php
    $periodNames = ['7d' => __('7 days'), '30d' => __('30 days')];
    $dayMax = max(1, (float) $daily->max(fn ($d) => array_sum($d->parts)));
    $histMax = max(1, max($summary->histogram));
    $bounds = Recorder::BUCKETS;
    $bucketLabels = [];
    foreach (range(0, 7) as $i) {
        $bucketLabels[$i] = $i === 0 ? '< '.$bounds[0] : ($i === 7 ? '≥ '.$bounds[6] : $bounds[$i - 1].'–'.$bounds[$i]);
    }
@endphp

@section('content')
    @include('torgash.speed::admin._style')

    <div class="speed-viz flex flex-col gap-6">
        <div class="flex flex-wrap items-center gap-3">
            <a href="{{ route('admin.speed.index', ['period' => $period]) }}" class="btn btn-ghost btn-sm btn-square" title="{{ __('Back to speed') }}" aria-label="{{ __('Back to speed') }}">
                <x-icon name="arrow-left" size="lg" />
            </a>
            <div class="flex-1 min-w-0">
                <h1 class="text-lg font-bold font-mono break-all">{{ $route }}</h1>
                <div class="text-xs opacity-60">{{ trans_choice(':count request measured|:count requests measured', $summary->requests) }}</div>
            </div>
            <div class="join">
                @foreach ($periodNames as $key => $label)
                    <a href="{{ route('admin.speed.route', ['r' => $route, 'period' => $key]) }}"
                       class="btn btn-sm join-item {{ $period === $key ? 'btn-primary' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        </div>

        {{-- The headline numbers, then one visit taken apart — as a bar and as
             text, so no part is read by its color alone. --}}
        <div class="card bg-base-100 border border-base-300/60 shadow-sm">
            <div class="card-body gap-4">
                <div class="grid grid-cols-2 md:grid-cols-5 gap-4">
                    <div><div class="text-xs opacity-60">{{ __('Typical') }}</div><div class="text-2xl font-bold">{{ Format::ms($summary->p50) }}</div></div>
                    <div><div class="text-xs opacity-60">{{ __('1 in 20 slower than') }}</div><div class="text-2xl font-bold">{{ Format::ms($summary->p95) }}</div></div>
                    <div><div class="text-xs opacity-60">{{ __('Slowest') }}</div><div class="text-2xl font-bold">{{ Format::ms($summary->max_ms) }}</div></div>
                    <div><div class="text-xs opacity-60">{{ __('Queries per visit') }}</div><div class="text-2xl font-bold">{{ number_format($summary->queries_per_request, 1) }}</div></div>
                    <div><div class="text-xs opacity-60">{{ __('Memory, peak') }}</div><div class="text-2xl font-bold">{{ Format::bytes($summary->memory_max) }}</div></div>
                </div>

                <div class="flex flex-col gap-2">
                    <div class="text-sm font-semibold">{{ __('One average visit') }} — {{ Format::ms($summary->avg_ms) }}</div>
                    @include('torgash.speed::admin._bar', ['barParts' => $summary->parts, 'barScale' => array_sum($summary->parts)])
                    <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 text-sm">
                        @foreach (Format::PARTS as $part => $label)
                            <div>
                                <span class="speed-swatch" data-part="{{ $part }}"></span>
                                {{ __($label) }}
                                <div class="font-semibold tabular-nums">
                                    {{ Format::ms($summary->parts[$part]) }}
                                    <span class="opacity-50 font-normal">{{ Format::percent($summary->avg_ms > 0 ? $summary->parts[$part] / $summary->avg_ms : 0) }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="grid xl:grid-cols-2 gap-6">
            {{-- Day by day, each day's average visit stacked by part: a regression
                 shows which part grew, not only that the total did. --}}
            <div class="card bg-base-100 border border-base-300/60 shadow-sm">
                <div class="card-body gap-3">
                    <div class="flex flex-wrap items-end gap-2">
                        <h2 class="card-title text-base flex-1">{{ __('Day by day') }}</h2>
                        @include('torgash.speed::admin._legend')
                    </div>
                    <div class="flex items-end gap-1 h-48 border-b border-base-300 pt-4">
                        @foreach ($daily as $d)
                            @php
                                $dayTotal = array_sum($d->parts);
                                $dayTitle = \Illuminate\Support\Carbon::parse($d->day)->isoFormat('D MMM').' — '.Format::ms($dayTotal).', '.trans_choice(':count request measured|:count requests measured', $d->requests)
                                    ."\n".collect(Format::PARTS)->map(fn ($l, $p) => __($l).': '.Format::ms($d->parts[$p]))->implode("\n");
                            @endphp
                            <div class="flex-1 h-full flex flex-col justify-end speed-hit rounded-t" title="{{ $dayTitle }}">
                                <div class="flex flex-col-reverse w-full" style="height: {{ max(1, $dayTotal / $dayMax * 100) }}%">
                                    @foreach (Format::PARTS as $part => $label)
                                        @if ($d->parts[$part] > 0 && $dayTotal > 0)
                                            <div data-part="{{ $part }}" style="flex: {{ $d->parts[$part] / $dayTotal }} 1 0; margin-top: 1px" class="{{ $loop->last ? 'rounded-t' : '' }}"></div>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="flex justify-between text-xs opacity-50">
                        <span>{{ $daily->first() ? \Illuminate\Support\Carbon::parse($daily->first()->day)->isoFormat('D MMM') : '' }}</span>
                        <span>{{ __('tallest') }}: {{ Format::ms($dayMax) }}</span>
                        <span>{{ $daily->last() ? \Illuminate\Support\Carbon::parse($daily->last()->day)->isoFormat('D MMM') : '' }}</span>
                    </div>
                </div>
            </div>

            {{-- How the visits spread: one number hides "usually fast, sometimes
                 two seconds"; a histogram cannot. --}}
            <div class="card bg-base-100 border border-base-300/60 shadow-sm">
                <div class="card-body gap-3">
                    <h2 class="card-title text-base">{{ __('How long visits took') }}</h2>
                    <div class="flex flex-col gap-1.5">
                        @foreach ($summary->histogram as $i => $count)
                            <div class="flex items-center gap-2 text-xs speed-hit rounded" title="{{ $bucketLabels[$i] }} ms: {{ trans_choice(':count request measured|:count requests measured', $count) }}">
                                <span class="w-20 text-right tabular-nums opacity-70">{{ $bucketLabels[$i] }} ms</span>
                                <div class="flex-1 h-3 rounded" style="background: var(--speed-track)">
                                    @if ($count > 0)
                                        <div class="h-full rounded bg-base-content/45" style="width: {{ max(1, $count / $histMax * 100) }}%"></div>
                                    @endif
                                </div>
                                <span class="w-14 tabular-nums">{{ number_format($count) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        <div class="grid xl:grid-cols-2 gap-6">
            @include('torgash.speed::admin._modules', ['speedModules' => $modules])
            @include('torgash.speed::admin._traces', ['speedTraces' => $traces])
        </div>

        @include('torgash.speed::admin._repeated', ['speedRepeated' => $repeated])
        @include('torgash.speed::admin._queries', ['speedQueries' => $queries])
    </div>
@endsection
