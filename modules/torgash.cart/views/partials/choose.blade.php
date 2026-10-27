{{-- The choice window (CartController::choose), drawn into the shared dialog.
     The buy box is the product page's own partial — its options fields, its
     quantity, its refusals answered into its own box — so choosing here and
     choosing on the page are one thing. A variation is a sibling PRODUCT: its
     button redraws this window for it (and is a plain link without script). --}}
@php $chooseShown = app(\Torgash\Catalog\Pricing\ShownPrice::class); @endphp
<div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-base-300">
    <h2 class="text-lg font-semibold">{{ __('Add to cart') }}</h2>
    <button type="button" class="btn btn-ghost btn-sm btn-circle" data-cart-dialog-close aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
</div>
<div class="px-5 py-4 flex flex-col gap-4 max-h-[70vh] overflow-y-auto">
    <div class="flex items-center gap-4 rounded-box border-2 border-primary/60 p-3">
        <span class="w-14 h-14 shrink-0 rounded bg-base-200 flex items-center justify-center overflow-hidden">
            @if ($chooseImage = $product->primaryImageUrl(160))
                <img src="{{ $chooseImage }}" alt="" class="object-contain w-full h-full">
            @else
                <x-icon name="image" class="opacity-30" />
            @endif
        </span>
        <span class="flex-1 min-w-0 leading-snug">{{ $product->t('name') }}</span>
        <span class="flex flex-col items-end shrink-0">
            <span class="text-lg font-bold torgash-price">{{ $chooseShown->format($product) }}</span>
            @if (! $chooseShown->isFrom($product) && $product->discountPercent() > 0)
                <span class="text-xs torgash-old-price">{{ format_price($product->oldPrice()) }}</span>
            @endif
        </span>
    </div>

    @include('torgash.cart::partials.variation-axes', ['axesRoute' => 'cart.choose'])

    @include('torgash.cart::partials.buy-box', ['product' => $product, 'buyBoxInDialog' => true])

    <a href="{{ route('catalog.product', $product->slug) }}" class="link link-hover text-sm inline-flex items-center gap-1 self-start">
        {{ __('More about this product') }} <x-icon name="chevron-right" size="sm" />
    </a>
</div>
