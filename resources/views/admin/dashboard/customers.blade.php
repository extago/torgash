<div class="grid grid-cols-3 gap-2">
    <div><div class="text-2xl font-bold tabular-nums leading-tight">{{ number_format($total, 0, '.', ' ') }}</div><div class="text-xs opacity-60">{{ __('in total') }}</div></div>
    <div><div class="text-2xl font-bold tabular-nums leading-tight">{{ $month }}</div><div class="text-xs opacity-60">{{ __('new in 30 days') }}</div></div>
    <div><div class="text-2xl font-bold tabular-nums leading-tight">{{ $week }}</div><div class="text-xs opacity-60">{{ __('new in 7 days') }}</div></div>
</div>
@if ($latest->isNotEmpty())
    <div class="flex flex-col">
        @foreach ($latest as $customer)
            <a href="{{ route('admin.customers.edit', $customer) }}" class="flex items-center gap-2 -mx-2 px-2 py-1 rounded-field hover:bg-base-200 text-sm">
                <span class="grow truncate">{{ $customer->name }} <span class="opacity-50 text-xs">{{ $customer->email }}</span></span>
                <span class="text-xs opacity-50 whitespace-nowrap">{{ $customer->created_at?->diffForHumans() }}</span>
            </a>
        @endforeach
    </div>
@endif
