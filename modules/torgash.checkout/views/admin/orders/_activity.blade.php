{{-- The order's history. Swapped out-of-band by the item editor, because an
     edit whose log line only appears after a page reload reads as an edit that
     did not happen. --}}
<div id="order-activity" @if ($oob ?? false) hx-swap-oob="true" @endif class="flex flex-col gap-3 mt-1">
    @forelse ($order->logs as $log)
        <div class="flex gap-3 text-sm">
            <span class="h-2 w-2 rounded-full bg-primary mt-1.5 shrink-0"></span>
            <div>
                <div>{{ $log->text() }}</div>
                <div class="text-xs opacity-50">{{ $log->created_at->format('Y-m-d H:i') }} · {{ $log->user?->name ?? __('System') }}</div>
            </div>
        </div>
    @empty
        <div class="text-sm opacity-50">{{ __('No activity recorded.') }}</div>
    @endforelse
</div>
