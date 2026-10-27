{{-- One menu item, two screens: which one this is, and the way to the other. --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.seo.index') }}" @class(['tab gap-2', 'tab-active' => $seoScreen === 'templates'])>
        <x-icon name="file-text" size="sm" /> {{ __('Templates') }}
    </a>
    <a role="tab" href="{{ route('admin.seo.audit') }}" @class(['tab gap-2', 'tab-active' => $seoScreen === 'audit'])>
        <x-icon name="list-checks" size="sm" /> {{ __('What is still empty') }}
    </a>
</div>
