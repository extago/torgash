{{-- Where to go, on the page the customer lands on after ordering.

     Read from the order's own frozen snapshot rather than from the warehouse
     row: renaming a shop or closing it must not change what an old order says
     the customer was told. --}}
<div class="border border-base-300 rounded-lg px-4 py-3 text-sm flex flex-col gap-1">
    <span class="text-xs font-semibold uppercase tracking-wide opacity-50">{{ __('Collect your order at') }}</span>
    <span class="font-medium">{{ $details['pickup_point'] ?? '' }}</span>
    @if (! empty($details['pickup_address']))
        <span class="opacity-70">{{ $details['pickup_address'] }}</span>
    @endif
    @if (! empty($details['pickup_phone']))
        <span class="opacity-70">{{ $details['pickup_phone'] }}</span>
    @endif
</div>
