{{-- One row of a wishlist — the owner's page and a shared one draw the same
     row (the latter with $wishEditable = false: no "remove"). Named wish*:
     an @include hands a partial everything its caller has. --}}
@php
    $wishFrom = $wishShown->isFrom($wishRow);
    $wishDiscount = $wishFrom ? 0 : $wishRow->discountPercent();
@endphp
{{-- On a phone the photograph stands beside the words and the price, and
     the buttons take a line of their own under them — stacked one under
     another, a row was half a screen high. From sm up, one line. --}}
<li class="grid grid-cols-[5rem_minmax(0,1fr)] gap-x-3 gap-y-2 p-3 sm:flex sm:items-center sm:gap-4 sm:p-4" data-wish-row>
    <a href="{{ route('catalog.product', $wishRow->slug) }}" class="row-span-2 w-20 h-20 shrink-0 rounded bg-base-200 flex items-center justify-center overflow-hidden">
        @if ($wishImage = $wishRow->primaryImageUrl(160))
            <img src="{{ $wishImage }}" alt="" class="object-contain w-full h-full" loading="lazy">
        @else
            <x-icon name="image" class="opacity-30" />
        @endif
    </a>
    <div class="flex-1 min-w-0 flex flex-col gap-1">
        <a href="{{ route('catalog.product', $wishRow->slug) }}" class="link link-hover leading-snug">{{ $wishRow->t('name') }}</a>
        @if ($wishPath = $wishPaths[$wishRow->id] ?? null)
            <div class="text-xs opacity-60 flex flex-wrap items-center gap-1">
                @foreach ($wishPath as $wishCrumb)
                    @if (! $loop->first)<x-icon name="chevron-right" size="sm" class="opacity-50" />@endif
                    <a href="{{ route('catalog.category', $wishCrumb['slug']) }}" class="link link-hover">{{ $wishCrumb['name'] }}</a>
                @endforeach
            </div>
        @endif
        <div class="text-xs flex flex-wrap items-center gap-x-4 gap-y-1">
            {!! hook_render('catalog.product.card_meta', ['product' => $wishRow]) !!}
            <span @class([
                'torgash-availability torgash-availability-'.$wishRow->effectiveAvailabilityKey(),
                'torgash-in-stock' => $wishRow->isOrderable(),
                'torgash-out-of-stock' => ! $wishRow->isOrderable(),
            ])>{{ $wishRow->stockLabel() }}</span>
            <span class="opacity-60">{{ __('SKU') }}: {{ $wishRow->sku }}</span>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-x-2 gap-y-1 sm:flex-col sm:flex-nowrap sm:items-end sm:w-44 shrink-0">
        <div class="flex items-baseline gap-2">
            <span class="text-lg font-bold torgash-price">{{ $wishShown->format($wishRow) }}</span>
            @if ($wishDiscount > 0)
                <span class="text-xs torgash-old-price">{{ format_price($wishRow->oldPrice()) }}</span>
            @endif
        </div>
        @if ($wishDiscount > 0)
            <div class="flex items-center gap-1 text-xs">
                <span class="badge badge-warning badge-sm">−{{ $wishDiscount }}%</span>
                <span class="opacity-70">{{ __('You save :amount', ['amount' => format_price(max(0, $wishRow->oldPrice() - $wishRow->effectivePrice()))]) }}</span>
            </div>
        @endif
        {!! hook_render('catalog.product.card_price_extra', ['product' => $wishRow]) !!}
    </div>
    <div class="col-span-2 flex items-center gap-2 shrink-0">
        {!! hook_render('catalog.product.card_actions', ['product' => $wishRow, 'place' => 'wishlist', 'quantity' => 'open']) !!}
        @if ($wishEditable)
        <form method="POST" action="{{ route('wishlist.remove', $wishRow->slug) }}" class="ml-auto sm:ml-0"
              hx-post="{{ route('wishlist.remove', $wishRow->slug) }}" hx-target="closest [data-wish-row]" hx-swap="outerHTML">
            @csrf
            <button class="btn btn-sm btn-touch btn-square btn-soft btn-error" title="{{ __('Remove from wishlist') }}" aria-label="{{ __('Remove from wishlist') }}: {{ $wishRow->t('name') }}">
                <x-icon name="x" size="lg" />
            </button>
        </form>
        @endif
    </div>
</li>
