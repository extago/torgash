{{--
    "Delivery to <town>" under the product page's buy panel — ProductEstimate:
    each method on offer for THIS product where the visitor says they are, its
    term and its price, priced the way the cart will price it.

    The town opens the shop's "your town" window when there is one (its save
    reloads the page, and this block with it). A module may add a line under
    an option through `shipping.product_delivery.note` — the warehouses module
    says when the chosen shop has it for pickup.

    Never "Free" for what is not: an option waiting for a human costs nothing
    in a total and an unknown amount in reality.
--}}
@php
    $deliveryHasTownWindow = \Illuminate\Support\Facades\Route::has('locations.dialog');
    $deliveryAddressed = ! $estimate['location']->isEmpty();
@endphp
<section class="torgash-product-delivery bg-base-100 border border-base-300 rounded-box p-4 flex flex-col gap-3">
    <div class="flex items-center gap-2 font-semibold">
        <x-icon name="truck" size="sm" class="text-primary shrink-0" />
        <span class="min-w-0">
            @if ($deliveryAddressed && $estimate['town'])
                {{ __('Delivery to :town', ['town' => $estimate['town']]) }}
            @else
                {{ __('Delivery') }}
            @endif
        </span>
        @if ($deliveryHasTownWindow)
            <button type="button" class="link link-hover text-sm font-normal ms-auto shrink-0"
                    hx-get="{{ route('locations.dialog') }}" hx-target="#torgash-location-body" hx-swap="innerHTML">
                {{ $deliveryAddressed ? __('Change') : __('Choose your town') }}
            </button>
        @endif
    </div>

    @if ($estimate['options']->isEmpty())
        <p class="text-sm opacity-70">
            {{ $deliveryAddressed ? __('We do not deliver there yet.') : __('Enter your town to see what is available there.') }}
        </p>
    @else
        <ul class="flex flex-col divide-y divide-base-300 -my-1">
            @foreach ($estimate['options'] as $deliveryOption)
                @php $deliveryNote = trim((string) hook_filter('shipping.product_delivery.note', '', ['option' => $deliveryOption, 'product' => $product])); @endphp
                <li class="flex items-start gap-3 py-2 text-sm">
                    @if ($deliveryOption->method->logoUrl())
                        <img src="{{ $deliveryOption->method->logoUrl() }}" alt="" class="h-6 w-8 object-contain shrink-0 mt-0.5">
                    @endif
                    <span class="flex flex-col min-w-0 grow">
                        <span>{{ $deliveryOption->label ?: $deliveryOption->method->t('name') }}</span>
                        @if ($deliveryOption->delivery_time)
                            <span class="text-xs opacity-60">{{ $deliveryOption->delivery_time }}</span>
                        @endif
                        @if ($deliveryNote !== '')
                            <span class="text-xs">{!! $deliveryNote !!}</span>
                        @endif
                    </span>
                    <span @class(['whitespace-nowrap text-end shrink-0', 'text-xs opacity-70' => $deliveryOption->pending_quote, 'text-success font-medium' => ! $deliveryOption->pending_quote && $deliveryOption->cost <= 0])>
                        @if ($deliveryOption->pending_quote)
                            {{ __('cost confirmed separately') }}
                        @else
                            {{ $deliveryOption->cost > 0 ? format_price($deliveryOption->cost) : __('Free') }}
                        @endif
                    </span>
                </li>
            @endforeach
        </ul>
        @unless ($deliveryAddressed)
            <p class="text-xs opacity-60">{{ __('For the town you choose the prices and terms may differ.') }}</p>
        @endunless
    @endif
</section>
