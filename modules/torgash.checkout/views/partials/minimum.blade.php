{{--
    The minimum order (Orders\MinimumOrder) as the customer sees it: the
    amount, how much of it the basket already holds as a bar, and what is
    missing. One partial for two places:

    - `cart`: the basket's summary, above its button (which the same rule
      holds disabled through `cart.checkout_allowed`) — drawn only while the
      goods are under the minimum; it is swapped with the basket on every
      change, so it is always the basket's own number;
    - the product page (setting `min_order_product`: `price`, `buy_box`,
      `after_buy_box`): drawn as a personal part (the basket is the
      visitor's) and fetched again whenever the basket changes on the page
      — cart's answers carry `HX-Trigger: torgash:cart-changed`.

    Variables are named minimum* on purpose: an @include hands a partial
    every variable its caller holds.
--}}
@php
    $minimumRule = app(\Torgash\Checkout\Orders\MinimumOrder::class);
    $minimumAmount = $minimumRule->amount();
    $minimumGoods = $minimumAmount > 0 ? (int) ($minimumGoods ?? app(\Torgash\Cart\CartManager::class)->total()) : 0;
    $minimumMissing = $minimumRule->shortfall($minimumGoods);
    $minimumInCart = ($minimumPlace ?? 'cart') === 'cart';
    $minimumPercent = $minimumAmount > 0 ? min(100, (int) floor($minimumGoods * 100 / $minimumAmount)) : 100;
@endphp
@if ($minimumAmount > 0 && ! ($minimumInCart && $minimumMissing === 0))
    <div @class([
            'flex flex-col gap-2',
            'rounded-box border border-primary/20 bg-primary/5 p-4' => $minimumInCart,
            'bg-base-100 border border-base-300 rounded-box p-4' => ($minimumPlace ?? null) === 'after_buy_box',
            'mt-3 text-sm' => in_array($minimumPlace ?? null, ['price', 'buy_box'], true),
         ])
         @unless ($minimumInCart)
             hx-get="{{ route('checkout.minimum', ['place' => $minimumPlace]) }}" hx-trigger="torgash:cart-changed from:body" hx-swap="outerHTML"
         @endunless>
        <div class="flex items-baseline justify-between gap-3 flex-wrap">
            <span @class(['font-semibold' => $minimumInCart, 'flex items-center gap-1.5' => ! $minimumInCart])>
                @unless ($minimumInCart)
                    <x-icon name="package-check" size="sm" class="opacity-60 shrink-0" />
                @endunless
                {{ __('Minimum order amount') }}@unless ($minimumInCart || $minimumGoods > 0): <span class="font-semibold whitespace-nowrap">{{ format_price($minimumAmount) }}</span>@endunless
            </span>
            @if ($minimumInCart || $minimumGoods > 0)
                <span @class(['font-semibold tabular-nums whitespace-nowrap', 'text-primary' => $minimumMissing > 0, 'text-success' => $minimumMissing === 0])>
                    {{ format_price($minimumGoods) }} / {{ format_price($minimumAmount) }}
                </span>
            @endif
        </div>
        @if ($minimumInCart || $minimumGoods > 0)
            <progress @class(['progress w-full h-2', 'progress-primary' => $minimumMissing > 0, 'progress-success' => $minimumMissing === 0])
                      value="{{ $minimumPercent }}" max="100" aria-label="{{ __('Minimum order amount') }}"></progress>
            @if ($minimumMissing > 0)
                <p @class(['opacity-80', 'text-sm text-end' => $minimumInCart, 'text-xs' => ! $minimumInCart])>
                    {!! __('Add goods worth :missing more to place the order', ['missing' => '<span class="font-semibold text-primary whitespace-nowrap">'.e(format_price($minimumMissing)).'</span>']) !!}
                </p>
            @else
                <p class="text-xs text-success flex items-center gap-1">
                    <x-icon name="check" size="sm" /> {{ __('The minimum order amount is reached') }}
                </p>
            @endif
        @endif
    </div>
@endif
