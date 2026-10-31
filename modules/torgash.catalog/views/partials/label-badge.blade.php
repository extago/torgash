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
@endphp
@if ($badgeTip !== '')
    {{-- The hint is core's (<x-popover hover>), like every hint on the
         storefront: it opens under the pointer, on focus, and with a tap on
         a phone, and turns away from the screen's edge by itself.
         pointer-events-auto: a card's corner lets clicks through to the
         photo beneath, and a hint that never sees the pointer never opens.
         A label at the bottom of the photo opens its hint upwards. --}}
    <x-popover hover :placement="str_starts_with($badgeCorner ?? 'top-left', 'bottom') ? 'top' : 'bottom'"
               class="inline-flex pointer-events-auto">
        {{ $badgeTip }}
        <x-slot:trigger>@include('torgash.catalog::partials.label-badge-face')</x-slot:trigger>
    </x-popover>
@else
    @include('torgash.catalog::partials.label-badge-face')
@endif
