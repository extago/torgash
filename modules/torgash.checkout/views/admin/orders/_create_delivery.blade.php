{{-- The deliveries the shop offers this basket to that address, priced now;
     replaced whenever either changes. No basket yet: nothing to price. Goods
     nobody sends: nothing to choose. No shipping module: PlaceOrder takes
     an order without one. --}}
@if ($quote === null)
    <p class="text-sm opacity-50">{{ __('Add a product and an address to see the deliveries.') }}</p>
@elseif (! $needsDelivery)
    <p class="text-sm opacity-60">{{ __('Nothing on this order is delivered anywhere.') }}</p>
@elseif ($quote->options->isEmpty())
    @if ($shippingActive ?? false)
        <p class="text-sm text-warning">{{ __('Nothing can be delivered to that address right now: give a delivery method a rate that covers it, or choose one that needs no address.') }}</p>
    @else
        <p class="text-sm opacity-60">{{ __('No delivery methods are set up; the order is placed without one.') }}</p>
    @endif
@else
    <div class="flex flex-col gap-2">
        @foreach ($quote->options as $optionId => $option)
            <label class="label cursor-pointer justify-start gap-3 p-0">
                <input type="radio" name="shipping_method_id" value="{{ $optionId }}" class="radio radio-sm" @checked((string) $optionId === (string) $selected)>
                <span class="grow">
                    {{ $option->label }}
                    @if ($option->delivery_time ?? null)
                        <span class="text-xs opacity-60">· {{ $option->delivery_time }}</span>
                    @endif
                </span>
                <span class="text-sm tabular-nums shrink-0">
                    @if ($option->pending_quote ?? false)
                        <span class="opacity-60">{{ __('priced later') }}</span>
                    @else
                        {{ admin_price((int) ($option->cost ?? 0)) }}
                    @endif
                </span>
            </label>
        @endforeach
    </div>
@endif
