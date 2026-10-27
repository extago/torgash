{{-- Quick view (CartController::quick), drawn into the shared dialog — wider
     than the choice window (`data-dialog-wide`): the pictures on the left, on
     the right what the choice window shows plus the short list of
     characteristics the shop chose for beside a buy button. The buy box is
     the product page's own partial, so buying here and there are one thing. --}}
@php
    $quickShown = app(\Torgash\Catalog\Pricing\ShownPrice::class);
    $quickImages = $product->galleryImages()->values()->map(fn ($m) => [
        'stage' => \Torgash\Media\Gallery::thumbUrl($m, 800),
        'thumb' => \Torgash\Media\Gallery::thumbUrl($m, 120),
    ])->all();
    if ($quickImages === []) {
        $quickImages = collect($product->externalImageUrls())->map(fn ($u) => ['stage' => $u, 'thumb' => $u])->all();
    }
    $quickShort = app(\Torgash\Catalog\Support\ShortDescription::class)->for($product);
@endphp
<div data-dialog-wide>
    <div class="flex items-center justify-between gap-3 px-5 py-4 border-b border-base-300">
        <h2 class="text-lg font-semibold leading-snug">{{ $product->t('name') }}</h2>
        <button type="button" class="btn btn-ghost btn-sm btn-circle shrink-0" data-cart-dialog-close aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
    </div>

    <div class="grid md:grid-cols-2 gap-6 px-5 py-4 max-h-[75vh] overflow-y-auto">
        <div class="flex flex-col gap-3" x-data="{ shown: 0 }">
            <div class="aspect-square bg-base-200 rounded-box overflow-hidden grid place-items-center">
                @forelse ($quickImages as $quickIndex => $quickImage)
                    <img src="{{ $quickImage['stage'] }}" alt="{{ $product->t('name') }}" class="object-contain w-full h-full"
                         @if ($quickIndex > 0) x-cloak @endif x-show="shown === {{ $quickIndex }}" loading="lazy">
                @empty
                    <x-no-image />
                @endforelse
            </div>
            @if (count($quickImages) > 1)
                <div class="flex flex-wrap gap-2">
                    @foreach ($quickImages as $quickIndex => $quickImage)
                        <button type="button" x-on:click="shown = {{ $quickIndex }}"
                                class="w-14 h-14 rounded border-2 bg-base-200 overflow-hidden"
                                :class="shown === {{ $quickIndex }} ? 'border-primary' : 'border-transparent'"
                                aria-label="{{ __('Picture :n', ['n' => $quickIndex + 1]) }}">
                            <img src="{{ $quickImage['thumb'] }}" alt="" class="object-contain w-full h-full" loading="lazy">
                        </button>
                    @endforeach
                </div>
            @endif
        </div>

        <div class="flex flex-col gap-4 min-w-0">
            <div class="flex items-baseline gap-3 flex-wrap">
                <span class="text-2xl font-bold torgash-price">{{ $quickShown->format($product) }}</span>
                @if (! $quickShown->isFrom($product) && $product->discountPercent() > 0)
                    <span class="torgash-old-price">{{ format_price($product->oldPrice()) }}</span>
                    <span class="badge badge-error badge-sm">−{{ $product->discountPercent() }}%</span>
                @endif
            </div>
            <div class="text-xs opacity-60">{{ __('SKU') }}: {{ $product->sku }}</div>

            @if ($quickShort !== '')
                <p class="text-sm opacity-80 leading-relaxed">{{ $quickShort }}</p>
            @endif

            @include('torgash.cart::partials.variation-axes', ['axesRoute' => 'cart.quick'])

            @include('torgash.cart::partials.buy-box', ['product' => $product, 'buyBoxInDialog' => true])

            @if ($specs->isNotEmpty())
                <div class="flex flex-col divide-y divide-base-300 border-t border-base-300">
                    @foreach ($specs as $quickSpec)
                        <div class="flex justify-between gap-3 py-2 text-sm">
                            <span class="opacity-60">{{ $quickSpec->feature->t('name') }}</span>
                            <span class="font-medium text-right">{{ $quickSpec->display() }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            <a href="{{ route('catalog.product', $product->slug) }}" class="link link-hover text-sm inline-flex items-center gap-1 self-start">
                {{ __('More about this product') }} <x-icon name="chevron-right" size="sm" />
            </a>
        </div>
    </div>
</div>
