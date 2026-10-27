{{-- The body of "Merge another order into this one" (PLAN §40.10/4): which
     order, what merging it does, or why it cannot — and only then the button.
     Fetched into the window over HTMX as the number is typed. --}}
<div id="order-merge-body" class="flex flex-col gap-3 text-sm">
    <label class="input input-bordered flex items-center gap-2">
        <x-icon name="search" size="sm" class="opacity-50" />
        <input type="text" name="number" value="{{ $number }}" placeholder="{{ __('Order number') }}" autocomplete="off"
               class="grow"
               hx-get="{{ route('admin.orders.merge.preview', $order) }}" hx-trigger="input changed delay:400ms"
               hx-target="#order-merge-result" hx-swap="outerHTML" hx-select="#order-merge-result">
    </label>

    <div id="order-merge-result" class="flex flex-col gap-3">

    @if ($candidates->isNotEmpty() && ! $other)
        <div>
            <div class="text-xs opacity-60 mb-1">{{ __('This customer’s other orders') }}</div>
            <div class="flex flex-wrap gap-1">
                @foreach ($candidates as $candidate)
                    <button type="button" class="btn btn-sm"
                            hx-get="{{ route('admin.orders.merge.preview', [$order, 'number' => $candidate->order_number]) }}"
                            hx-target="#order-merge-result" hx-swap="outerHTML" hx-select="#order-merge-result">
                        <span class="font-mono">{{ $candidate->order_number }}</span>
                        <span class="opacity-60">{{ $candidate->adminMoney($candidate->total) }}</span>
                    </button>
                @endforeach
            </div>
        </div>
    @endif

    @if ($number !== '' && ! $other)
        <div class="alert alert-warning">{{ __('No order with that number.') }}</div>
    @elseif ($other)
        <div class="rounded-box border border-base-300 p-3 flex flex-col gap-1">
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.orders.show', $other) }}" target="_blank" class="font-mono link">{{ $other->order_number }}</a>
                <span class="opacity-60">{{ $other->created_at->format('d.m.Y H:i') }}</span>
                <span class="ml-auto font-semibold">{{ $other->adminMoney($other->total) }}</span>
            </div>
            <div class="opacity-80">{{ $other->customerLabel() }} · {{ $other->email ?: $other->phone }}</div>
            <ul class="list-disc ml-5 opacity-80">
                @foreach ((array) $other->items as $item)
                    <li>{{ $item['name'] ?? '' }} × {{ $item['qty'] ?? 0 }}</li>
                @endforeach
            </ul>
        </div>

        @if ($refusals)
            <div class="alert alert-error flex flex-col items-start gap-1">
                @foreach ($refusals as $reason)
                    <span>{{ $reason }}</span>
                @endforeach
            </div>
        @else
            <ul class="list-disc ml-5 opacity-80">
                <li>{{ __('Its goods join this order with their discounts; nothing goes back to the shelf.') }}</li>
                @if ((int) $other->paid_total > 0)
                    <li>{{ __(':amount paid for it counts towards this order.', ['amount' => $other->adminMoney($other->paid_total)]) }}</li>
                @endif
                @if ((int) $other->shipping_cost > 0)
                    <li>{{ __('Its delivery (:amount) is dropped: there is one parcel now, this order’s.', ['amount' => $other->adminMoney($other->shipping_cost)]) }}</li>
                @endif
                <li>{{ __('It stays as “Merged” with a link here, and can be split off again until anything happens to either order.') }}</li>
            </ul>
            <form method="POST" action="{{ route('admin.orders.merge', $order) }}">
                @csrf
                <input type="hidden" name="from" value="{{ $other->order_number }}">
                <button class="btn btn-primary btn-sm">
                    <x-icon name="merge" size="sm" /> {{ __('Merge :number into this order', ['number' => $other->order_number]) }}
                </button>
            </form>
        @endif
    @endif
    </div>
</div>
