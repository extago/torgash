{{-- The labels the shop placed in one of the product page's rows OFF the
     photo — `$labelRow` is 'above-description' or 'under-buy'
     (LabelsController::DETAIL_ROWS). Drawn by the same label-badge partial as
     the corners; a picture label also prints its words under it here. A TILE
     label carries its own words inside the square. --}}
@php $labelRowItems = collect($labels ?? [])->filter(fn ($rowLabel) => $rowLabel->position_detail === $labelRow)->values(); @endphp
@if ($labelRowItems->isNotEmpty())
    <div @class(['torgash-label-row flex flex-wrap items-start gap-2', $labelRowClass ?? ''])>
        @foreach ($labelRowItems as $rowLabel)
            @php $rowText = $rowLabel->displayText($product); @endphp
            @if ($rowLabel->style === 'image' && $rowLabel->imageUrl())
                <span class="torgash-pictogram">
                    @include('torgash.catalog::partials.label-badge', [
                        'badgeLabel' => $rowLabel, 'badgePlace' => 'detail', 'badgeText' => $rowText, 'badgeCorner' => 'top-left',
                    ])
                    @if (trim($rowText) !== '')
                        <span class="torgash-pictogram-text">{{ $rowText }}</span>
                    @endif
                </span>
            @else
                @include('torgash.catalog::partials.label-badge', [
                    'badgeLabel' => $rowLabel, 'badgePlace' => 'detail', 'badgeText' => $rowText, 'badgeCorner' => 'top-left',
                ])
            @endif
        @endforeach
    </div>
@endif
