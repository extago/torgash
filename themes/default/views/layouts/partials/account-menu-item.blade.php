{{-- One row of the header's account menu (account-menu.blade.php). --}}
<li>
    <a href="{{ $accountItem['url'] }}" class="flex items-center gap-2.5 py-2">
        <x-icon :name="$accountItem['icon'] ?? 'circle'" size="sm" class="opacity-60 shrink-0" />
        <span class="grow">{{ $accountItem['label'] }}</span>
        @if (! empty($accountItem['badge']))
            <span class="badge badge-primary badge-sm tabular-nums">{{ $accountItem['badge'] }}</span>
        @endif
    </a>
</li>
