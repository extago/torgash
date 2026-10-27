{{-- The customer's orders, drawn into the inbox's "this customer" card through
     enquiries.admin.person. --}}
<div class="flex flex-col gap-1">
    <div class="text-xs opacity-60 flex items-center justify-between">
        <span>{{ __('Orders') }}</span>
        <span class="tabular-nums">{{ $personOrderCount }}</span>
    </div>
    @foreach ($personOrders as $personOrder)
        @php $personStatus = $personStatuses[$personOrder->status] ?? null; @endphp
        <a href="{{ route('admin.orders.show', $personOrder->order_number) }}"
           class="flex items-center gap-2 text-sm rounded-box px-2 py-1 -mx-2 hover:bg-base-200">
            <span class="h-2 w-2 rounded-full shrink-0" style="background-color: {{ $personStatus?->color ?? '#999' }}"
                  title="{{ $personStatus?->t('name') ?? $personOrder->status }}"></span>
            <span class="font-medium">{{ $personOrder->order_number }}</span>
            <span class="text-xs opacity-50">{{ $personOrder->created_at?->format('d.m.Y') }}</span>
            <span class="ms-auto tabular-nums">{{ $personOrder->adminMoney($personOrder->total) }}</span>
        </a>
    @endforeach
</div>
