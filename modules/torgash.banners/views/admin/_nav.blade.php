{{-- Banners, the sliders they stand in, and the templates that draw both — one subject, three screens. --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.banners.index') }}" @class(['tab gap-2', 'tab-active' => $bannersScreen === 'banners'])>
        <x-icon name="image" size="sm" /> {{ __('Banners') }}
    </a>
    <a role="tab" href="{{ route('admin.banners.sliders.index') }}" @class(['tab gap-2', 'tab-active' => $bannersScreen === 'sliders'])>
        <x-icon name="gallery-horizontal-end" size="sm" /> {{ __('Sliders') }}
    </a>
    @can('banners.templates')
        <a role="tab" href="{{ route('admin.banners.templates.index') }}" @class(['tab gap-2', 'tab-active' => $bannersScreen === 'templates'])>
            <x-icon name="code-xml" size="sm" /> {{ __('Templates') }}
        </a>
    @endcan
</div>
