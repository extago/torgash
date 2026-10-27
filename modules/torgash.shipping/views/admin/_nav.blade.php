{{-- Delivery methods and the zones they are priced in: one subject, two
     screens — the customers screens' own arrangement. --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.shipping.methods.index') }}" @class(['tab gap-2', 'tab-active' => $shippingScreen === 'methods'])>
        <x-icon name="truck" size="sm" /> {{ __('Shipping methods') }}
    </a>
    <a role="tab" href="{{ route('admin.shipping.zones.index') }}" @class(['tab gap-2', 'tab-active' => $shippingScreen === 'zones'])>
        <x-icon name="map" size="sm" /> {{ __('Shipping zones') }}
    </a>
</div>
