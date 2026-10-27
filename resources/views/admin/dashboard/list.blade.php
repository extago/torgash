{{--
    The common shape of a dashboard widget, for a module that has numbers and
    a short list and nothing else to say: `stats` [{label, value, url?, tone?
    good|bad|warn}] on top, `rows` [{label, hint?, url?, value?, tone?}] under
    them, `empty` when there are none, `footer` [{label, url}] links at the
    bottom. Written by core so eight modules do not draw eight lists.
    Rendered with view('admin.dashboard.list', [...]).
--}}
@php
    $toneClass = fn ($tone) => match ($tone ?? null) {
        // A warning as TEXT is the warning colour mixed with the theme's ink:
        // daisyUI's warning is a background colour, unreadable as text on a
        // light card, and a fixed amber is too dark on a dark one.
        'good' => 'text-success', 'bad' => 'text-error', 'warn' => 'text-[color-mix(in_oklab,var(--color-warning)_60%,var(--color-base-content))]', default => '',
    };
@endphp
@if (! empty($stats))
    <div @class(['grid gap-2', 'grid-cols-2' => count($stats) === 2 || count($stats) === 4, 'grid-cols-3' => count($stats) === 3, 'grid-cols-1' => count($stats) === 1])>
        @foreach ($stats as $stat)
            @php $statTag = ! empty($stat['url']) ? 'a' : 'div'; @endphp
            <{{ $statTag }} @if (! empty($stat['url'])) href="{{ $stat['url'] }}" @endif
                class="rounded-box bg-base-200/60 px-3 py-2 {{ ! empty($stat['url']) ? 'hover:bg-base-200' : '' }}">
                <div class="text-xl font-bold tabular-nums leading-tight {{ $toneClass($stat['tone'] ?? null) }}">{{ $stat['value'] }}</div>
                <div class="text-xs opacity-60">{{ $stat['label'] }}</div>
            </{{ $statTag }}>
        @endforeach
    </div>
@endif

@if (! empty($rows))
    <div class="flex flex-col">
        @foreach ($rows as $row)
            @php $rowTag = ! empty($row['url']) ? 'a' : 'div'; @endphp
            <{{ $rowTag }} @if (! empty($row['url'])) href="{{ $row['url'] }}" @endif
                class="flex items-center gap-2 -mx-2 px-2 py-1.5 rounded-field text-sm {{ ! empty($row['url']) ? 'hover:bg-base-200' : '' }}">
                <span class="grow min-w-0">
                    <span class="block truncate">{{ $row['label'] }}</span>
                    @if (! empty($row['hint']))
                        <span class="block truncate text-xs opacity-50">{{ $row['hint'] }}</span>
                    @endif
                </span>
                @if (isset($row['value']) && $row['value'] !== '')
                    <span class="tabular-nums whitespace-nowrap text-xs font-medium {{ $toneClass($row['tone'] ?? null) }}">{{ $row['value'] }}</span>
                @endif
            </{{ $rowTag }}>
        @endforeach
    </div>
@elseif (! empty($empty))
    <p class="text-sm opacity-60 py-2">{{ $empty }}</p>
@endif

@if (! empty($footer))
    <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs">
        @foreach ($footer as $footerLink)
            <a href="{{ $footerLink['url'] }}" class="link link-hover opacity-70">{{ $footerLink['label'] }}</a>
        @endforeach
    </div>
@endif
