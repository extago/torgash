{{-- "Delivery to <your town>" under the product page's buy panel — a
     personal part of the page (ShippingServiceProvider), drawn alone: it
     works out the estimate itself. Nothing for a product nobody sends. --}}
@php $estimate = app(\Torgash\Shipping\ProductEstimate::class)->for($product); @endphp
@if ($estimate !== null)
    @include('torgash.shipping::partials.product-delivery', ['estimate' => $estimate, 'product' => $product])
@endif
