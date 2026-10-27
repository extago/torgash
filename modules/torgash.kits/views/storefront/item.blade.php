@php
    // One thing in the set: the main product (no tick — it is what the page is
    // about) or one slot, with its tick, its crossed-out price and, where the
    // shop allows it, a way to choose a different one.
    $isMain = $slot === null;
    $required = $required ?? false;
    $swappable = $swappable ?? false;
@endphp
{{-- Narrow enough that two fit side by side in the 400px column under the
     buy button, and the card's own width (not the screen's) decides when
     there is room for more. `shrink-0` matters only in the scrolling layout,
     where a flex row would otherwise squeeze every cell to fit instead of
     letting the row overflow and scroll. --}}
<div class="w-36 @2xl:w-40 shrink-0 flex flex-col gap-1.5" @if (! $isMain) data-kit-slot="{{ $slot->id }}" @endif>
    {{-- EVERY cell opens with a row of the same height, which is what puts the
         photographs on one baseline: a slot's is its tick, and the main
         product's is a word saying what it is. Before this the main product
         had no such row and its picture sat a line higher than everything
         beside it. --}}
    @if ($isMain)
        <div class="flex items-center gap-2 h-6">
            <span class="badge badge-primary badge-sm shrink-0">{{ __('This product') }}</span>
        </div>
    @else
        <label class="label cursor-pointer justify-start gap-2 p-0 h-6">
            {{-- A required slot is not a choice: it is what the kit IS, so it
                 is ticked and disabled rather than quietly submitted behind a
                 box nobody can untick. --}}
            <input type="checkbox" name="slots[{{ $slot->id }}]" value="{{ $product->id }}"
                   class="checkbox checkbox-primary" checked @disabled($required)>
            @if ($required)
                <input type="hidden" name="slots[{{ $slot->id }}]" value="{{ $product->id }}">
            @endif
            {{-- The name is printed under the picture; repeated here it only
                 fitted cut off, so the tick says what it does instead. --}}
            <span class="label-text text-xs">{{ __('In the set') }}<span class="sr-only">: {{ $product->t('name') }}</span></span>
        </label>
    @endif

    <a href="{{ route('catalog.product', $product->slug) }}" class="block relative">
        <div class="aspect-square rounded-box bg-base-200 overflow-hidden flex items-center justify-center">
            @if ($image = $product->primaryImageUrl(240))
                <img src="{{ $image }}" alt="{{ $product->t('name') }}" class="object-contain h-full w-full"
                     loading="lazy" decoding="async">
            @else
                <x-no-image class="w-full h-full rounded" size="sm" />
            @endif
        </div>
        @if ($full > $price)
            <span class="badge torgash-discount badge-sm absolute top-1 start-1">
                −{{ (int) round(($full - $price) / max(1, $full) * 100) }}%
            </span>
        @endif
    </a>

    <div class="text-xs line-clamp-2 leading-snug">{{ $product->t('name') }}</div>

    @if ($isMain)
        {{-- Its price and what it is bought with: one piece the quote
             replaces (main-extra), because answering a question in the buy
             panel changes both. --}}
        @include('torgash.kits::storefront.main-extra', ['kitId' => $kit->id, 'mainPrice' => $price, 'mainParts' => $mainParts ?? []])
    @else
        <div class="flex items-baseline gap-1.5">
            <span class="font-semibold {{ $full > $price ? 'torgash-discount-text' : 'torgash-price' }}">{{ format_price($price) }}</span>
            @if ($full > $price)
                <span class="text-xs torgash-old-price">{{ format_price($full) }}</span>
            @endif
            @if ($qty > 1)
                <span class="text-xs opacity-50">×{{ $qty }}</span>
            @endif
        </div>
    @endif

    @if ($swappable)
        {{-- "Choose another": the same set the slot stands for, narrowed by
             what fits this product — asked of the server, because the set IS a
             catalogue query. --}}
        <button type="button" class="link link-primary text-xs self-start"
                hx-get="{{ route('kits.picker', ['kit' => $kit, 'slot' => $slot, 'main' => $main->id]) }}"
                {{-- The whole form rides along, so the picker knows what the
                     other slots already hold and never offers it twice. By id
                     rather than `closest form`, because the window it fills is
                     a sibling of the form and not inside it. --}}
                hx-include="#kit-form-{{ $kit->id }}"
                hx-target="#kit-picker-body-{{ $kit->id }}"
                hx-swap="innerHTML"
                {{-- Opened here and filled by the request above: a window that
                     opened only once its contents had arrived would look, for
                     the length of a round trip, like a button that does
                     nothing. --}}
                data-open-dialog="kit-picker-{{ $kit->id }}">
            <x-icon name="refresh-cw" size="sm" class="inline" /> {{ __('Choose another') }}
        </button>
    @endif
</div>
