{{-- Re-quoting a placed order's delivery, on the order card.

     Same shape as the checkout form: everything the quote depends on lives in
     one container, and a single htmx binding covers every way it can change —
     a town picked from the list, a postal code typed, a method chosen. Only
     the METHODS block is swapped, never the town picker: re-rendering that
     would throw away what the admin is in the middle of typing.

     There is no <form> here on purpose. The order card already carries other
     forms (manager, payment), and a form inside a form loses its method spoof —
     so the inputs are gathered by `hx-include` instead. --}}
<div id="order-delivery" class="flex flex-col gap-2 mt-1"
     hx-get="{{ route('admin.shipping.orders.delivery', $order) }}"
     hx-trigger="destination-changed, change from:#order-delivery-postal, change from:#order-delivery-methods"
     hx-include="#order-delivery"
     hx-target="#order-delivery-methods" hx-swap="innerHTML">

    <fieldset class="fieldset p-0">
        <legend class="fieldset-legend">{{ __('Town') }}</legend>
        @include('torgash.shipping::partials.town-field', [
            'town' => $order->city,
            'placeId' => $order->place_id,
            'country' => $order->country,
            'size' => '',
            'required' => false,
        ])
    </fieldset>

    <fieldset class="fieldset p-0">
        <legend class="fieldset-legend">{{ __('Address') }}</legend>
        <input name="address_line" value="{{ $order->address_line }}" class="input input-bordered w-full">
    </fieldset>

    <fieldset class="fieldset p-0">
        <legend class="fieldset-legend">{{ __('Postal code') }}</legend>
        <input id="order-delivery-postal" name="postal_code" value="{{ $order->postal_code }}"
               class="input input-bordered w-full">
    </fieldset>

    <div id="order-delivery-methods" class="flex flex-col gap-2 mt-1">
        @include('torgash.shipping::admin.orders.delivery-methods', ['order' => $order, 'quote' => $quote])
    </div>
</div>
