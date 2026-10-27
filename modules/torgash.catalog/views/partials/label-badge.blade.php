{{-- ONE label, as the storefront draws it — the card, the product page and the
     admin's own previews all come through here, so a label cannot look one way
     in the list of labels and another way on the shop.

     $badgeLabel  the Label
     $badgePlace  'list' (a card) or 'detail' (the product page): size differs
     $badgeText   what it says (already resolved for the product)
     $badgeCorner which corner it stands in — its hint opens away from the edge
     $badgeHint   false to draw no hint (the admin's previews) --}}
@php
    $badgeTip = ($badgeHint ?? true) ? $badgeLabel->tooltipText() : '';
    $badgeRight = str_ends_with($badgeCorner ?? 'top-left', 'right');
    $badgeBottom = str_starts_with($badgeCorner ?? 'top-left', 'bottom');
@endphp
@if ($badgeTip !== '')
    {{-- Focusable, so a tap on a phone opens it where there is no hover.
         pointer-events-auto: a card's corner lets clicks through to the
         link, and a hint that never sees the pointer never opens. --}}
    <span class="torgash-label-tip pointer-events-auto" tabindex="0">
@endif
@if ($badgeLabel->style === 'tile')
    {{-- A square: the picture (the label's upload, else its Lucide icon) and
         the words under it — a pictogram. The size step is the tile's side. --}}
    @php $tileSide = $badgeLabel->tileSide($badgePlace); @endphp
    <span class="torgash-label-tile" style="--tile-side: {{ $tileSide }}px; --tile-accent: {{ $badgeLabel->tileAccent() }}">
        @if ($tileImage = $badgeLabel->imageUrl())
            <img src="{{ $tileImage }}" alt="" class="torgash-label-tile-pic">
        @elseif ($badgeLabel->icon)
            <x-icon :name="$badgeLabel->icon" class="torgash-label-tile-pic" />
        @endif
        @if (trim($badgeText) !== '')
            <span class="torgash-label-tile-text">{{ $badgeText }}</span>
        @endif
    </span>
@elseif ($badgeLabel->style === 'image' && ($badgeImage = $badgeLabel->imageUrl()))
    @php $badgeBox = $badgeLabel->imageBox($badgePlace); @endphp
    <img src="{{ $badgeImage }}" alt="{{ $badgeText }}" style="{{ $badgeBox['style'] }}"
         class="block drop-shadow {{ $badgeBox['class'] }}">
@else
    <span class="{{ $badgeLabel->badgeClass($badgePlace) }} shadow" @if ($badgeLabel->usesCustomColors()) style="{{ $badgeLabel->colorStyle() }}" @endif>
        {{ $badgeText }}
    </span>
@endif
@if ($badgeTip !== '')
        {{-- data-inert-text (actions.js): on a card it sits inside the
             product's link, and its words are to be read and copied. --}}
        <span role="tooltip" data-inert-text @class([
            'torgash-label-tip-panel',
            'right-0' => $badgeRight, 'left-0' => ! $badgeRight,
            'bottom-full mb-2' => $badgeBottom, 'top-full mt-2' => ! $badgeBottom,
        ])>{{ $badgeTip }}</span>
    </span>
@endif
