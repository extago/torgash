{{-- The town in the header's corner (storefront.header.start). Opens "your
     town" — its body is fetched when the window opens, so a page that nobody
     asks about does not build it. A personal part of the page, drawn alone:
     it finds the visitor's town itself. Named loc* — an @include hands a
     partial everything its caller has. --}}
@php
    $locPlaceId = (int) hook_filter('storefront.visitor_place', null);
    $locPlace = $locPlaceId ? \Torgash\Locations\Models\Place::find($locPlaceId) : null;
@endphp
<button type="button" data-location-open
        hx-get="{{ route('locations.dialog') }}" hx-target="#torgash-location-body" hx-swap="innerHTML"
        class="btn btn-ghost btn-sm btn-touch gap-1.5 normal-case font-normal max-w-[16rem] px-2 {{ $locHeader === 'dark' ? 'text-neutral-content/80 hover:text-neutral-content' : '' }}">
    <x-icon name="navigation" size="sm" class="shrink-0" />
    <span class="truncate">{{ $locPlace?->t('name') ?? __('Choose your town') }}</span>
</button>
