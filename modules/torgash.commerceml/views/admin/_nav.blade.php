{{-- The exchange with 1C: connecting it, matching what it sends, and the
     orders going back — one subject, four screens. --}}
@php $commercemlKind = request()->route('kind'); @endphp
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.commerceml.index') }}" @class(['tab gap-2', 'tab-active' => request()->routeIs('admin.commerceml.index')])>
        <x-icon name="plug" size="sm" /> {{ __('Connection') }}
    </a>
    <a role="tab" href="{{ route('admin.commerceml.matching', 'price_type') }}" @class(['tab gap-2', 'tab-active' => $commercemlKind === 'price_type'])>
        <x-icon name="tags" size="sm" /> {{ __('Price types') }}
    </a>
    <a role="tab" href="{{ route('admin.commerceml.matching', 'feature') }}" @class(['tab gap-2', 'tab-active' => $commercemlKind === 'feature'])>
        <x-icon name="sliders-horizontal" size="sm" /> {{ __('Characteristics') }}
    </a>
    <a role="tab" href="{{ route('admin.commerceml.orders') }}" @class(['tab gap-2', 'tab-active' => request()->routeIs('admin.commerceml.orders')])>
        <x-icon name="package" size="sm" /> {{ __('Orders') }}
    </a>
</div>
