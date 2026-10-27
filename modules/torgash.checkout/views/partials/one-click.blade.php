{{-- Spacing belongs to the seam that places this, not to the button. --}}
<div>
    <x-modal :name="'one-click-'.$product->id"
             :title="__('Buy in one click')"
             icon="zap"
             size="sm"
             :trigger="__('Buy in one click')"
             trigger-class="btn btn-outline w-full gap-2">
        {{-- The bare product's price only for a product that asks nothing:
             a build's price is the buy box's, and quoting the bare one here
             promised a number the order would not have. --}}
        <p class="text-sm opacity-70 mb-3">
            {{ $product->t('name') }}@unless (hook_filter('cart.product.asks', false, ['product' => $product])) — {{ format_price($product->effectivePrice()) }}@endunless
        </p>

        @include('torgash.checkout::partials.one-click-form', ['product' => $product, 'oneClickBuyForm' => $oneClickBuyForm ?? null])
    </x-modal>
</div>
