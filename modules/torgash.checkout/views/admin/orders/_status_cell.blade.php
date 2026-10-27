{{-- An order's status in its list row, changed in place — through the same
     OrderStatusChanger its own page uses (stock, the log, the customer's
     email). The dot is the shop's own colour for the status. --}}
@php $rowStatus = $statuses->get($order->status); @endphp
<td id="order-status-{{ $order->id }}">
    @if ($order->status === \Torgash\Checkout\Models\OrderStatus::MERGED)
        {{-- Closed: shown, never changed (PLAN §40.10/4). --}}
        <span class="badge badge-sm gap-1.5 bg-base-100 border-base-300 whitespace-nowrap">
            <span class="h-2 w-2 rounded-full inline-block shrink-0" style="background-color: {{ $rowStatus?->color ?? '#999' }}"></span>
            {{ $rowStatus?->t('name') ?? $order->status }}
        </span>
    @else
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" class="badge badge-sm gap-1.5 cursor-pointer bg-base-100 border-base-300 whitespace-nowrap" title="{{ __('Change status') }}">
            <span class="h-2 w-2 rounded-full inline-block shrink-0" style="background-color: {{ $rowStatus?->color ?? '#999' }}"></span>
            {{ $rowStatus?->t('name') ?? $order->status }}
            <x-icon name="chevron-down" size="sm" class="opacity-60" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-56 mt-1 p-1 z-50 border border-base-300">
            @foreach ($statuses as $status)
                @continue($status->code === \Torgash\Checkout\Models\OrderStatus::MERGED)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.orders.status', $order) }}"
                            hx-vals='@json(['status' => $status->code, 'cell' => 1])'
                            hx-target="#order-status-{{ $order->id }}" hx-swap="outerHTML"
                            @class(['font-semibold' => $status->code === $order->status])>
                        <span class="h-2 w-2 rounded-full inline-block shrink-0" style="background-color: {{ $status->color ?? '#999' }}"></span>
                        {{ $status->t('name') }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
    @endif
</td>
