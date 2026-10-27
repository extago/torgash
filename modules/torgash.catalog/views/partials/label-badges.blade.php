@php
    $corners = ['top-left' => [], 'top-right' => [], 'bottom-left' => [], 'bottom-right' => []];
    foreach (($labels ?? []) as $badgeLabel) {
        $corner = $position === 'detail' ? $badgeLabel->position_detail : $badgeLabel->position_list;
        // A product-page row (over the description, under the buy button) is
        // drawn by partials/label-row, not on the photo.
        if (isset($corners[$corner])) {
            $corners[$corner][] = $badgeLabel;
        }
    }
    $cornerClasses = [
        'top-left' => 'top-2 left-2 items-start',
        // A card with buttons in that corner (catalog.product.card_corner)
        // hands its top-right labels the space beside them.
        'top-right' => ($badgesRightInset ?? false) ? 'top-2 right-14 items-end' : 'top-2 right-2 items-end',
        'bottom-left' => 'bottom-2 left-2 items-start',
        'bottom-right' => 'bottom-2 right-2 items-end',
    ];
@endphp
@php $badgesExtra = trim($badgesExtra ?? ''); @endphp
@foreach ($corners as $corner => $group)
    @if (! empty($group) || ($corner === 'top-left' && $badgesExtra !== ''))
        {{-- On a card the labels let clicks through to the card's link; on
             the product page they are text a customer may want to select. --}}
        <div @class(['torgash-label-corner absolute', $cornerClasses[$corner], 'z-10 flex flex-col gap-1', 'pointer-events-none' => $position !== 'detail'])>
            @foreach ($group as $badgeLabel)
                @include('torgash.catalog::partials.label-badge', [
                    'badgeLabel' => $badgeLabel,
                    'badgePlace' => $position === 'detail' ? 'detail' : 'list',
                    'badgeText' => $badgeLabel->displayText($product),
                    'badgeCorner' => $corner,
                ])
            @endforeach
            {{-- A module's marks (catalog.product.card_flags) go under the
                 top-left labels, in the same stack, so they never overlap. --}}
            @if ($corner === 'top-left' && $badgesExtra !== '')
                {!! $badgesExtra !!}
            @endif
        </div>
    @endif
@endforeach
