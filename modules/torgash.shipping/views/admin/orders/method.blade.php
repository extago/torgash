{{-- The by-hand fallback on the order card: any method, any price, past the
     zones and the rates. Rendered into checkout's screen through a hook, so
     uninstalling delivery removes it and the card still shows the method name
     checkout froze onto the order itself.

     Folded away under the re-quote above, which is the normal way: open by
     itself only when that is the only way (nothing is offered at this
     address) or already how the order ships. --}}
<details class="rounded-box border border-base-300 mt-2 group" @if (! ($offered ?? true) || $manual) open @endif>
    <summary class="cursor-pointer list-none flex items-center gap-2 px-4 py-3 text-sm">
        <x-icon name="pencil" size="sm" class="opacity-60" />
        <span class="font-medium grow">{{ __('Set the method and price by hand') }}</span>
        @if ($manual)
            <span class="badge badge-sm badge-warning">{{ __('set by hand') }}</span>
        @endif
        <x-icon name="chevron-down" size="sm" class="opacity-60 transition-transform group-open:rotate-180" />
    </summary>

    <form method="POST" action="{{ route('admin.shipping.orders.method', $order) }}" class="flex flex-col gap-3 px-4 pb-4">
        @csrf
        @method('PATCH')

        <p class="text-xs opacity-60">
            {{ __('For when the address above finds nothing — a town no zone covers, an order with no town. Writes any method and any price into the order, past the zones and the rates.') }}
        </p>

        <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_12rem_auto] items-end">
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Method') }}</legend>
                <select name="shipping_method_name" class="select select-bordered w-full">
                    <option value="">{{ __('No delivery') }}</option>
                    @foreach ($names as $name)
                        <option value="{{ $name }}" @selected($name === $order->shipping_method_name)>{{ $name === $order->shipping_method_name ? ($order->shippingMethodLabel() ?? $name) : $name }}</option>
                    @endforeach
                </select>
            </fieldset>
            <fieldset class="fieldset p-0">
                <legend class="fieldset-legend">{{ __('Cost') }}</legend>
                <input name="shipping_cost" type="number" step="0.01" min="0" required
                       value="{{ number_format($order->shipping_cost / 100, 2, '.', '') }}"
                       class="input input-bordered w-full" placeholder="0.00">
            </fieldset>
            <button class="btn btn-primary">{{ __('Save') }}</button>
        </div>

        @if ($manual)
            <div class="text-xs opacity-60">
                {{ __('Set by hand — the carrier details from checkout no longer apply.') }}
            </div>
        @endif
    </form>
</details>
