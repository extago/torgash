{{-- The bell's rows, open: what is waiting, from every module that answers. --}}
@forelse ($rows as $row)
    <a href="{{ $row['url'] }}" class="flex items-center gap-3 -mx-2 px-2 py-1.5 rounded-field hover:bg-base-200">
        {{-- The warning colour mixed with the theme's own ink: dark amber on a
             light card, light amber on a dark one — `warning-content` is the
             ink FOR a warning background and vanished on the dark theme. --}}
        <span class="w-8 h-8 rounded-full bg-warning/20 text-[color-mix(in_oklab,var(--color-warning)_60%,var(--color-base-content))] flex items-center justify-center shrink-0"><x-icon :name="$row['icon']" size="sm" /></span>
        <span class="grow text-sm">{{ $row['label'] }}</span>
        <span class="badge badge-warning badge-sm tabular-nums">{{ $row['count'] }}</span>
    </a>
@empty
    <div class="flex items-center gap-3 py-2 text-sm">
        <span class="w-8 h-8 rounded-full bg-success/15 text-success flex items-center justify-center shrink-0"><x-icon name="check-check" size="sm" /></span>
        <span class="opacity-70">{{ __('Nothing is waiting.') }}</span>
    </div>
@endforelse
