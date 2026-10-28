{{-- Modules by the time their hook listeners took — their OWN time (a hook
     they fired in turn is charged to whoever answered it), and the database
     part of it. $speedModules from Reports::modules(). --}}
@php $modulesMax = max(0.001, (float) $speedModules->max('per_request')); @endphp
<div class="card bg-base-100 border border-base-300/60 shadow-sm">
    <div class="card-body gap-3">
        <div>
            <h2 class="card-title text-base">{{ __('Modules') }}</h2>
            <p class="text-xs opacity-60">{{ __('What each module\'s hooks add to a page they run on, and how much of that is database. Its pages\' own controllers and templates are in "code & templates" of those pages.') }}</p>
        </div>

        @if ($speedModules->isEmpty())
            <p class="text-sm opacity-60">{{ __('No module hooks measured yet.') }}</p>
        @else
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>{{ __('Module') }}</th>
                        <th class="text-right">{{ __('Per page') }}</th>
                        <th class="text-right">{{ __('Of it, database') }}</th>
                        <th class="text-right">{{ __('Share of the time') }}</th>
                        <th class="w-1/3"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($speedModules->take(15) as $m)
                        @php
                            $dbShare = $m->ms > 0 ? min(1, $m->db_ms / $m->ms) : 0;
                            $width = $m->per_request / $modulesMax * 100;
                        @endphp
                        <tr class="speed-hit">
                            <td class="font-mono text-xs">{{ $m->module }}</td>
                            <td class="text-right tabular-nums">{{ \Torgash\Speed\Format::ms($m->per_request) }}</td>
                            <td class="text-right tabular-nums">{{ \Torgash\Speed\Format::percent($dbShare) }}</td>
                            <td class="text-right tabular-nums">{{ \Torgash\Speed\Format::percent($m->share) }}</td>
                            <td>
                                <div class="h-3 rounded" style="background: var(--speed-track)"
                                     title="{{ __('Per page') }}: {{ \Torgash\Speed\Format::ms($m->per_request) }} · {{ __('Of it, database') }}: {{ \Torgash\Speed\Format::percent($dbShare) }} · {{ number_format($m->calls) }} {{ __('calls') }}">
                                    <div class="h-full flex" style="width: {{ max(1, $width) }}%">
                                        @if ($dbShare > 0)
                                            <div class="speed-seg" data-part="db" style="flex: {{ $dbShare }} 1 0"></div>
                                        @endif
                                        @if ($dbShare < 1)
                                            <div class="speed-seg" data-part="hooks" style="flex: {{ 1 - $dbShare }} 1 0"></div>
                                        @endif
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</div>
