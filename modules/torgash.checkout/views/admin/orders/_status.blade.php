@php $current = $statuses->firstWhere('code', $order->status); @endphp
<form id="order-status" hx-patch="{{ route('admin.orders.status', $order) }}" hx-target="#order-status" hx-swap="outerHTML"
      class="flex items-center gap-2 w-full">
    @csrf
    <span class="inline-block h-2.5 w-2.5 rounded-full shrink-0" style="background-color: {{ $current?->color ?? '#999' }}"></span>
    <select name="status" class="select select-bordered w-full" data-submit-on-change>
        @foreach ($statuses as $status)
            <option value="{{ $status->code }}" @selected($status->code === $order->status)>{{ $status->t('name') }}</option>
        @endforeach
    </select>
</form>
