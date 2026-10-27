<div class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
    <a href="{{ route('admin.modules') }}" class="flex items-center gap-2 hover:underline"><x-icon name="layout-grid" size="sm" class="opacity-60" /> {{ __('Modules') }}</a>
    <span class="text-end tabular-nums">{{ $modules }} / {{ $modulesTotal }}</span>
    <span class="flex items-center gap-2"><x-icon name="store" size="sm" class="opacity-60" /> {{ __('Storefronts') }}</span>
    <span class="text-end tabular-nums">{{ $storefronts }}</span>
    <span class="flex items-center gap-2"><x-icon name="globe" size="sm" class="opacity-60" /> {{ __('Languages') }}</span>
    <span class="text-end tabular-nums">{{ $languages }}</span>
    <span class="flex items-center gap-2"><x-icon name="credit-card" size="sm" class="opacity-60" /> {{ __('Currencies') }}</span>
    <span class="text-end tabular-nums">{{ $currencies }}</span>
    <span class="flex items-center gap-2"><x-icon name="list-checks" size="sm" class="opacity-60" /> {{ __('Queue') }}</span>
    <span class="text-end">
        @if ($workerSilent)
            <span class="badge badge-sm badge-warning" title="php artisan queue:work">{{ __('no worker') }}</span>
        @else
            <span class="badge badge-sm badge-success">{{ __('working') }}</span>
        @endif
        <span class="text-xs opacity-60 tabular-nums">{{ $pending }}</span>
    </span>
    @if ($failed)
        <span class="flex items-center gap-2"><x-icon name="circle-x" size="sm" class="opacity-60" /> {{ __('Failed jobs') }}</span>
        <span class="text-end tabular-nums text-error">{{ $failed }}</span>
    @endif
</div>
