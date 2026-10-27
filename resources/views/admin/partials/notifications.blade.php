{{-- The bell (App\Core\Admin\AdminNotifications): what is waiting, from
     whichever modules answer. Refreshes itself every minute — the only thing
     in the top bar that changes while a page sits open. --}}
@php $bellRows = app(\App\Core\Admin\AdminNotifications::class)->all(); $bellTotal = array_sum(array_column($bellRows, 'count')); @endphp
<div id="admin-bell" class="dropdown dropdown-end"
     hx-get="{{ route('admin.notifications') }}" hx-trigger="every 60s" hx-swap="outerHTML">
    <div tabindex="0" role="button" class="btn btn-ghost btn-sm btn-square relative" title="{{ __('Notifications') }}" aria-label="{{ __('Notifications') }}">
        <x-icon name="bell" size="lg" />
        @if ($bellTotal > 0)
            <span class="badge badge-error badge-xs absolute -top-1 -right-1 px-1 min-w-4 h-4 text-[10px] font-semibold">{{ $bellTotal > 99 ? '99+' : $bellTotal }}</span>
        @endif
    </div>
    <div tabindex="0" class="dropdown-content bg-base-100 rounded-box shadow-lg w-72 mt-3 p-2 z-50 border border-base-300">
        @forelse ($bellRows as $bellRow)
            <a href="{{ $bellRow['url'] }}" class="flex items-center gap-3 px-3 py-2 rounded-field hover:bg-base-200">
                <span class="w-8 h-8 rounded-full bg-primary/10 text-primary flex items-center justify-center shrink-0"><x-icon :name="$bellRow['icon']" /></span>
                <span class="grow text-sm">{{ $bellRow['label'] }}</span>
                <span class="badge badge-primary badge-sm tabular-nums">{{ $bellRow['count'] }}</span>
            </a>
        @empty
            <div class="px-3 py-6 text-center text-sm opacity-60">
                <x-icon name="check-check" size="lg" class="mx-auto mb-1 opacity-60" />
                {{ __('Nothing is waiting.') }}
            </div>
        @endforelse
    </div>
</div>
