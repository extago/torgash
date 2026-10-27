{{-- The build of one line, opened in place of the items card. A configurator
     is a page of questions and does not fit in a table cell, so this replaces
     the whole card and Cancel puts it back.

     The controls themselves come from whoever owns the meaning
     (`checkout.order.line_config_form`) — checkout supplies the form around
     them, the frame and the two buttons, and knows nothing about what is
     inside. --}}
<div id="order-editor">
    <div class="flex justify-between items-center">
        <h2 class="card-title">{{ __('Change the build') }}</h2>
        <button type="button" class="btn btn-sm btn-ghost"
                hx-get="{{ route('admin.orders.items.edit', $order) }}"
                hx-target="#order-editor" hx-swap="outerHTML">{{ __('Cancel') }}</button>
    </div>

    <div class="text-sm opacity-60">
        {{ $line['sku'] }} · {{ $line['name'] }} ×{{ $line['qty'] }}
    </div>

    <form class="flex flex-col gap-3 mt-3"
          hx-patch="{{ route('admin.orders.items.reconfigure', ['order' => $order, 'line' => (string) ($line['key'] ?? $line['product_id'])]) }}"
          hx-target="#order-editor" hx-swap="outerHTML">
        @csrf

        {!! $form !!}

        {{-- What this does to the money, said before it is done: the answers
             are priced by today's rules while the product keeps the price this
             order sold it at, and a manager who wanted otherwise has the unit
             price field waiting on the row. --}}
        <div class="text-xs opacity-60">
            {{ __('The product keeps the price this order sold it at; the answers are priced as they are today.') }}
            @if ($order->stock_deducted)
                {{ __('This order holds stock: a part that changes moves the warehouse too.') }}
            @endif
        </div>

        <div class="flex gap-2">
            <button class="btn btn-sm btn-primary">{{ __('Apply') }}</button>
            <button type="button" class="btn btn-sm btn-ghost"
                    hx-get="{{ route('admin.orders.items.edit', $order) }}"
                    hx-target="#order-editor" hx-swap="outerHTML">{{ __('Cancel') }}</button>
        </div>
    </form>
</div>
