{{-- "The whole cart in one click" under the checkout button of the cart page
     (setting one_click_cart): the same form as a product's, sending the
     basket instead of a product. --}}
<x-modal name="one-click-cart"
         :title="__('Buy in one click')"
         icon="zap"
         size="sm"
         :trigger="__('Buy in one click')"
         trigger-class="btn btn-outline btn-lg w-full gap-2">
    <p class="text-sm opacity-70 mb-3">
        {{ trans_choice('{1}:count item|[2,*]:count items', $oneClickQty, ['count' => $oneClickQty]) }} — {{ format_price($oneClickTotal, exact: true) }}
    </p>

    @include('torgash.checkout::partials.one-click-form', ['product' => null])
</x-modal>
