{{-- What a label LOOKS like — a tile, a picture or a badge — with no hint.
     label-badge.blade.php draws it alone or as the button of its hint. --}}
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
