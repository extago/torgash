{{-- A collection's looks on the storefront: each picture with its points;
     a point opens a small card of its product (core's <x-popover>) — and,
     with the basket module, its quick view. One query for every product of
     every look (Look::pointsFor, with the cards' data). Named look*: an
     @include hands a partial every variable its caller has. --}}
@php
    $lookPoints = \Torgash\Collections\Models\Look::pointsFor($looksShown);
    $lookShown = app(\Torgash\Catalog\Pricing\ShownPrice::class);
    $lookQuick = \Illuminate\Support\Facades\Route::has('cart.quick') && setting('torgash.cart', 'quick_view', true);
@endphp
@if ($looksShown->isNotEmpty())
    <div class="flex flex-col gap-6 {{ $looksClass ?? 'mb-6' }}">
        @foreach ($looksShown as $lookItem)
            <figure class="bg-base-100 border border-base-300 rounded-box overflow-hidden">
                <div class="relative">
                    <img src="{{ $lookItem->imageUrl() }}" alt="{{ $lookItem->t('caption') ?: '' }}" class="w-full block" loading="lazy">
                    @foreach ($lookPoints[$lookItem->id] ?? [] as $lookPoint)
                        @php $lookProduct = $lookPoint['product']; @endphp
                        <span class="absolute -translate-x-1/2 -translate-y-1/2" style="left: {{ $lookPoint['x'] }}%; top: {{ $lookPoint['y'] }}%">
                            <x-popover :label="$lookProduct->t('name')" class="w-8 h-8 rounded-full bg-base-100/90 shadow-lg ring-4 ring-base-100/40 grid! place-items-center transition-transform hover:scale-110" panel-class="w-72 max-w-[calc(100vw-2rem)] p-3">
                                <div class="flex gap-3">
                                    <span class="w-16 h-16 shrink-0 rounded bg-base-200 overflow-hidden grid place-items-center">
                                        @if ($lookImage = $lookProduct->primaryImageUrl(160))
                                            <img src="{{ $lookImage }}" alt="" class="object-contain w-full h-full">
                                        @else
                                            <x-icon name="image" class="opacity-30" />
                                        @endif
                                    </span>
                                    <span class="min-w-0 flex flex-col gap-1">
                                        <a href="{{ route('catalog.product', $lookProduct->slug) }}" class="link link-hover text-sm leading-snug line-clamp-2">{{ $lookProduct->t('name') }}</a>
                                        <span class="font-bold torgash-price">{{ $lookShown->format($lookProduct) }}</span>
                                    </span>
                                </div>
                                {{-- One under the other, the full width: side by side in a
                                     panel this narrow the words broke over two lines and
                                     the second button ran out of the panel. --}}
                                <div class="flex flex-col gap-2 mt-3">
                                    @if ($lookQuick)
                                        <a href="{{ route('catalog.product', $lookProduct->slug) }}" hx-get="{{ route('cart.quick', $lookProduct->slug) }}" hx-target="#torgash-cart-dialog-body" hx-swap="innerHTML"
                                           class="btn btn-primary btn-sm w-full">{{ __('Quick view') }}</a>
                                    @endif
                                    <a href="{{ route('catalog.product', $lookProduct->slug) }}" @class(['btn btn-sm w-full', 'btn-primary' => ! $lookQuick, 'btn-outline' => $lookQuick])>{{ __('More about this product') }}</a>
                                </div>
                                <x-slot:trigger>
                                    <span class="w-3 h-3 rounded-full bg-primary"></span>
                                    <span class="sr-only">{{ $lookProduct->t('name') }}</span>
                                </x-slot:trigger>
                            </x-popover>
                        </span>
                    @endforeach
                </div>
                @if ($lookItem->t('caption'))
                    <figcaption class="px-4 py-3 text-sm opacity-80">{{ $lookItem->t('caption') }}</figcaption>
                @endif
            </figure>
        @endforeach
    </div>
@endif
