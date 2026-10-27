{{-- The three levels a shop switches on and off, in the order it thinks about
     them, and the way to fill them: one subject, four screens under the
     layout's single heading. --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.locations.countries') }}" @class(['tab gap-2', 'tab-active' => request()->routeIs('admin.locations.countries')])>
        <x-icon name="globe" size="sm" /> {{ __('Countries') }}
    </a>
    <a role="tab" href="{{ route('admin.locations.regions') }}" @class(['tab gap-2', 'tab-active' => request()->routeIs('admin.locations.regions')])>
        <x-icon name="map" size="sm" /> {{ __('Regions') }}
    </a>
    <a role="tab" href="{{ route('admin.locations.index') }}" @class(['tab gap-2', 'tab-active' => request()->routeIs('admin.locations.index')])>
        <x-icon name="map-pin" size="sm" /> {{ __('Places') }}
    </a>
    <a role="tab" href="{{ route('admin.locations.import') }}" @class(['tab gap-2', 'tab-active' => request()->routeIs('admin.locations.import')])>
        <x-icon name="upload" size="sm" /> {{ __('Import') }}
    </a>
</div>
