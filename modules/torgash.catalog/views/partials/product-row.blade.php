{{-- One product as a compact ROW — the listing's "list" view, for a buyer
     who knows what they want and is picking forty lines of it: a small
     photo, the name and code, the price, and the basket's button with the
     quantity already open beside it (`quantity => open`, the shape cart's
     list-buy partial draws for a row that has the room).

     The same hooks a card answers, so every module that has something to say
     on a card says it here too: the meta line (rating, points), the stock
     line (the shop the visitor picked), the actions (the basket) and the
     corner (the heart, comparison) — drawn inline rather than over a photo
     too small to carry them. The grid primes the set first, exactly as it
     does for cards (Support\ProductCards::prime()), so a row costs no query
     a card would not.

     Like a card, the row is a DIV with links inside and the forms beside
     them: a form inside an <a> is invalid markup whose click is the link's. --}}
@php
    $rowUrl = route('catalog.product', $product->slug);
    $rowCorner = hook_render('catalog.product.card_corner', ['product' => $product]);
    $rowShownPrice = app(\Torgash\Catalog\Pricing\ShownPrice::class);
    $rowPriceIsFrom = $rowShownPrice->isFrom($product);
    $rowStockLine = hook_filter('catalog.product.stock_line', null, ['product' => $product]);
@endphp
<div class="torgash-row grid grid-cols-[4rem_minmax(0,1fr)] sm:grid-cols-[4.5rem_minmax(0,1fr)_auto] items-center gap-x-4 gap-y-2 px-4 py-3 hover:bg-base-200/40 transition-colors">
    <a href="{{ $rowUrl }}" class="row-span-2 sm:row-span-1 block aspect-square bg-base-200 rounded-field overflow-hidden" tabindex="-1" aria-hidden="true">
        @if ($rowImage = $product->primaryImageUrl(400))
            <img src="{{ $rowImage }}" alt="" class="w-full h-full object-contain" loading="lazy" decoding="async">
        @else
            <x-no-image class="w-full h-full" />
        @endif
    </a>

    <div class="min-w-0 flex flex-col gap-0.5">
        <a href="{{ $rowUrl }}" class="text-sm font-medium leading-snug line-clamp-2 hover:text-primary">{{ $product->t('name') }}</a>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs">
            @if (setting('torgash.catalog', 'card_sku', true))
                <span class="uppercase opacity-50">{{ __('SKU') }}: {{ $product->sku }}</span>
            @endif
            @if ($rowStockLine !== null)
                {!! $rowStockLine !!}
            @else
                <span @class([
                    'torgash-availability torgash-availability-'.$product->effectiveAvailabilityKey(),
                    'torgash-in-stock' => $product->isOrderable(),
                    'torgash-out-of-stock' => ! $product->isOrderable(),
                ])>{{ $product->stockLabel() }}</span>
            @endif
        </div>
        {{-- ONE row however many modules speak: the rating, a bonus, a video
             mark side by side, the way a shopper reads them — each listener
             printing its own block stacked them into a column. --}}
        @php $cardMeta = trim(hook_render('catalog.product.card_meta', ['product' => $product])); @endphp
        @if ($cardMeta !== '')
            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-0.5">{!! $cardMeta !!}</div>
        @endif
        @if (($cardShortDescription ?? '') !== '')
            <div class="text-xs opacity-50 leading-snug line-clamp-1 max-sm:hidden">{{ $cardShortDescription }}</div>
        @endif
    </div>

    <div class="col-start-2 sm:col-start-3 flex items-center justify-between sm:justify-end gap-4">
        <a href="{{ $rowUrl }}" class="flex flex-col items-start sm:items-end min-w-0 sm:min-w-28" tabindex="-1">
            {{-- Not beside a "from" — see product-card.blade.php. --}}
            @if (! $rowPriceIsFrom && $product->discountPercent() > 0)
                <span class="text-xs torgash-old-price whitespace-nowrap">{{ format_price($product->oldPrice()) }}</span>
            @endif
            <span class="text-lg font-bold leading-tight whitespace-nowrap torgash-price">{{ $rowShownPrice->format($product) }}</span>
        </a>
        <div class="flex items-center gap-2 shrink-0">
            {{-- A fixed width for the basket's place (its stepper, a gap and the button,
                 10rem), filled or not: a row that cannot be bought would
                 otherwise slide its price to the right, out of the column
                 every other row's price stands in. --}}
            <div class="flex justify-end sm:min-w-40">
                {!! hook_render('catalog.product.card_actions', ['product' => $product, 'quantity' => 'open']) !!}
            </div>
            @if ($rowCorner !== '')
                {{-- Not on a phone: there the row's second line is the price
                     and the basket, and the heart would push the price under
                     the stepper. The grid view still carries it. --}}
                <div class="torgash-row-corner flex items-center gap-2 max-sm:hidden">{!! $rowCorner !!}</div>
            @endif
        </div>
    </div>
</div>
