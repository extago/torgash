{{-- Shown on the order card while the delivery has no price yet. Rendered into
     checkout's screen through a hook, so uninstalling delivery removes it. --}}
@php
    $reasons = [
        'quote_on_request' => __('This method is always priced by hand.'),
        'weight_missing' => __('Some product in this order has no weight, so the rate could not be applied.'),
        'dimensions_missing' => __('This method charges by volume, and some product in this order has no measured box.'),
        'carrier_unavailable' => __('The carrier gave no price for this address.'),
    ];
@endphp
<div class="alert alert-warning flex-col items-start gap-2 mt-2 text-sm">
    <div class="font-medium">{{ __('Delivery still needs a price') }}</div>
    <div class="opacity-80">{{ $reasons[$reason] ?? __('The price could not be worked out automatically.') }}</div>

    <form method="POST" action="{{ route('admin.shipping.orders.cost', $order) }}" class="flex items-end gap-2 w-full">
        @csrf
        <fieldset class="fieldset p-0 grow">
            <legend class="fieldset-legend">{{ __('Delivery cost') }}</legend>
            <input name="shipping_cost" type="number" step="0.01" min="0" required
                   value="{{ $order->shipping_cost > 0 ? $order->shipping_cost / 100 : '' }}"
                   class="input input-bordered w-full" placeholder="0.00">
        </fieldset>
        <button class="btn btn-primary">{{ __('Set it') }}</button>
    </form>

    <div class="text-xs opacity-70">{{ __('The order total is recalculated with it.') }}</div>
</div>
