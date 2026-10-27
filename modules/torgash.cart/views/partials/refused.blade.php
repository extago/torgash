{{-- The answer to an add that was refused from a LIST (a card's button, the
     search window), where there is no buy box to say it in: the reason, in
     core's corner notice. The basket did not change, so nothing else comes. --}}

<x-notice :title="__('Not added to your basket')" :text="$message"
          :image="$product->primaryImageUrl(160)" icon="circle-alert" icon-class="text-error"
          :href="route('catalog.product', $product->slug)" :action="__('Open the product')" />
