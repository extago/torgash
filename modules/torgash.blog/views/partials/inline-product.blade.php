{{-- A product inside an article's text ({product:SKU}, Blog\Article): its
     photo, name and price as the catalogue shows them, and the way to it.
     Named distinctively — it is rendered from inside the article's text. --}}
@php
    $inlinePhoto = $inlineProduct->primaryImageUrl(480);
    $inlinePrice = app(\Torgash\Catalog\Pricing\ShownPrice::class);
@endphp
<div class="torgash-rich-embed my-6 flex flex-col sm:flex-row items-center gap-5 rounded-box border border-base-300 bg-base-100 p-4">
    <a href="{{ route('catalog.product', $inlineProduct->slug) }}" class="shrink-0 w-40 h-40 grid place-items-center">
        @if ($inlinePhoto)
            <img src="{{ $inlinePhoto }}" alt="{{ $inlineProduct->t('name') }}" loading="lazy" class="max-w-full max-h-full object-contain">
        @else
            <x-no-image class="w-full h-full" />
        @endif
    </a>
    <div class="flex-1 min-w-0 flex flex-col gap-2 text-center sm:text-start">
        <a href="{{ route('catalog.product', $inlineProduct->slug) }}" class="font-semibold hover:text-primary">{{ $inlineProduct->t('name') }}</a>
        <div class="text-lg font-bold">
            @if ($inlinePrice->isFrom($inlineProduct))
                <span class="text-sm font-normal opacity-70">{{ __('from') }}</span>
            @endif
            {{ $inlinePrice->format($inlineProduct) }}
        </div>
        <div>
            <a href="{{ route('catalog.product', $inlineProduct->slug) }}" class="btn btn-neutral btn-sm">{{ __('Open in the catalogue') }}</a>
        </div>
    </div>
</div>
