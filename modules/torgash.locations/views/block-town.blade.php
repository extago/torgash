{{-- "Your town" as a block — a row that opens the same window as the
     header's corner. The town is read through checkout's
     storefront.visitor_place, as the header button reads it. --}}
@php
    $townId = (int) hook_filter('storefront.visitor_place', null);
    $townPlace = $townId ? \Torgash\Locations\Models\Place::find($townId) : null;
@endphp
<button type="button" hx-get="{{ route('locations.dialog') }}" hx-target="#torgash-location-body" hx-swap="innerHTML"
        class="torgash-block torgash-block-town w-full flex items-center gap-3 px-5 py-3 min-h-12 text-left hover:bg-base-200">
    <x-icon name="navigation" class="w-5 h-5 shrink-0 text-primary" />
    <span class="flex-1 min-w-0 truncate">{{ $townPlace?->t('name') ?? __('Choose your town') }}</span>
    <span class="text-sm opacity-60">{{ __('Change') }}</span>
</button>
