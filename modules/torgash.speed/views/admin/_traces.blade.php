{{-- Pages that kept their timeline: slower than the threshold, or opened
     while "trace my pages" was on. $speedTraces from Reports::traces(). --}}
<div class="card bg-base-100 border border-base-300/60 shadow-sm">
    <div class="card-body gap-3">
        <div>
            <h2 class="card-title text-base">{{ __('Timelines') }}</h2>
            <p class="text-xs opacity-60">{{ __('Slow pages keep their whole timeline — every query, outside call and hook in order. So does every page opened while "Trace my pages" is on.') }}</p>
        </div>

        @if ($speedTraces->isEmpty())
            <p class="text-sm opacity-60">{{ __('No timelines kept in this period.') }}</p>
        @else
            <ul class="divide-y divide-base-300/60 text-sm">
                @foreach ($speedTraces as $t)
                    <li>
                        <a href="{{ route('admin.speed.trace', $t->id) }}" class="speed-hit flex items-center gap-3 py-1.5 px-1 rounded">
                            <span class="tabular-nums w-16 text-right font-semibold {{ $t->total_ms >= 1000 ? 'text-error' : '' }}">{{ \Torgash\Speed\Format::ms($t->total_ms) }}</span>
                            <span class="font-mono text-xs flex-1 truncate" title="{{ $t->path }}">{{ $t->path }}</span>
                            @if ($t->requested)
                                <span class="badge badge-sm badge-ghost" title="{{ __('Opened while tracing') }}"><x-icon name="scan-search" size="sm" /></span>
                            @endif
                            @if ($t->status >= 500)
                                <span class="badge badge-sm badge-error">{{ $t->status }}</span>
                            @endif
                            <span class="text-xs opacity-50 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($t->created_at)->diffForHumans() }}</span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
