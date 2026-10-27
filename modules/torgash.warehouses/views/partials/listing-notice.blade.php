{{-- Above a listing that is one shop's assortment (catalog.listing.notice,
     PLAN §38): says so, names the shop, and is the way to change it — the
     button opens "your town", where the shop and the switch are. --}}
<div class="rounded-box bg-primary text-primary-content px-5 py-3.5 flex items-center gap-4">
    <div class="flex-1 min-w-0 leading-snug">
        <div class="opacity-90">{{ __('Stock is shown for the shop:') }}</div>
        <div class="font-semibold truncate">{{ $whPoint->t('name') }}</div>
    </div>
    <button type="button" hx-get="{{ route('locations.dialog') }}" hx-target="#torgash-location-body" hx-swap="innerHTML"
            class="btn btn-touch btn-square bg-primary-content/15 hover:bg-primary-content/25 border-0 text-primary-content shrink-0"
            aria-label="{{ __('Change the shop') }}" title="{{ __('Change the shop') }}">
        <x-icon name="store" size="lg" />
    </button>
</div>
