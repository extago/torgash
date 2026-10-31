{{--
    Free delivery as a goal (FreeShippingGoal): from what sum, how much of it
    the basket already holds as a bar, and what is missing — or that it is
    won. One partial for two places:

    - `cart`: the basket's summary (setting `free_shipping_cart`), swapped
      with the basket on every change;
    - the product page (setting `free_shipping_product`: `price`,
      `buy_box`, `after_buy_box`): a personal part, fetched again when the
      basket changes on the page (`torgash:cart-changed`, cart's).

    `$shippingGoal` may be handed in (the cart has it already); otherwise it
    is asked here. Variables are named shippingGoal* on purpose: an @include
    hands a partial every variable its caller holds.
--}}
@php
    $shippingGoal = $shippingGoal ?? app(\Torgash\Promotions\FreeShippingGoal::class)->state();
    $shippingGoalPlace = $shippingGoalPlace ?? 'cart';
    $shippingGoalInCart = $shippingGoalPlace === 'cart';
    $shippingGoalPercent = $shippingGoal && ! $shippingGoal['active'] && $shippingGoal['threshold']
        ? min(100, (int) floor($shippingGoal['goods'] * 100 / $shippingGoal['threshold'])) : 100;
    $shippingGoalRefresh = $shippingGoalInCart ? null : route('promotions.free_shipping', ['place' => $shippingGoalPlace]);
@endphp
@if ($shippingGoal)
    <div @class([
            'flex flex-col gap-2',
            'rounded-box border border-base-300 p-4' => $shippingGoalInCart,
            'bg-base-100 border border-base-300 rounded-box p-4' => $shippingGoalPlace === 'after_buy_box',
            'mt-3 text-sm' => in_array($shippingGoalPlace, ['price', 'buy_box'], true),
         ])
         @if ($shippingGoalRefresh) hx-get="{{ $shippingGoalRefresh }}" hx-trigger="torgash:cart-changed from:body" hx-swap="outerHTML" @endif>
        <div class="flex items-center gap-3">
            <x-icon name="truck" :size="$shippingGoalInCart ? 'xl' : 'md'" class="text-primary shrink-0" />
            <div class="grow min-w-0 leading-tight">
                <div class="font-semibold">{{ __('Free delivery') }}</div>
                @if ($shippingGoal['threshold'])
                    <div class="text-xs opacity-70">{!! __('on orders from :amount', ['amount' => '<span class="font-semibold whitespace-nowrap">'.e(format_price($shippingGoal['threshold'])).'</span>']) !!}</div>
                @endif
            </div>
            @if ($shippingGoal['active'])
                <span class="font-semibold text-success whitespace-nowrap">{{ __('Activated') }}</span>
            @elseif ($shippingGoal['goods'] > 0)
                <span class="font-semibold tabular-nums whitespace-nowrap text-primary">{{ format_price($shippingGoal['goods']) }} / {{ format_price($shippingGoal['threshold']) }}</span>
            @endif
        </div>
        @if ($shippingGoal['active'] || $shippingGoal['goods'] > 0)
            <progress @class(['progress w-full h-2', 'progress-success' => $shippingGoal['active'], 'progress-primary' => ! $shippingGoal['active']])
                      value="{{ $shippingGoalPercent }}" max="100" aria-label="{{ __('Free delivery') }}"></progress>
            <p @class(['text-xs', 'opacity-80' => ! $shippingGoal['active'], 'text-success font-medium' => $shippingGoal['active']])>
                @if ($shippingGoal['active'])
                    {{ __('Delivery of this order is free') }}
                @else
                    {!! __('Add goods worth :missing more and delivery is free', ['missing' => '<span class="font-semibold text-primary whitespace-nowrap">'.e(format_price($shippingGoal['missing'])).'</span>']) !!}
                @endif
            </p>
        @endif
    </div>
@elseif ($shippingGoalRefresh)
    {{-- Nothing to say now (a basket of goods nobody sends) — still the place the next answer goes. --}}
    <div hidden hx-get="{{ $shippingGoalRefresh }}" hx-trigger="torgash:cart-changed from:body" hx-swap="outerHTML"></div>
@endif
