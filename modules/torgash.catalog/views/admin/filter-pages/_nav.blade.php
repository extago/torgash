{{-- One menu item, two screens: the published pages, and the rules that make them. --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.catalog.filter-pages.index') }}" @class(['tab gap-2', 'tab-active' => $filterScreen === 'pages'])>
        <x-icon name="filter" size="sm" /> {{ __('Pages') }}
    </a>
    <a role="tab" href="{{ route('admin.catalog.filter-rules.index') }}" @class(['tab gap-2', 'tab-active' => $filterScreen === 'rules'])>
        <x-icon name="wand-sparkles" size="sm" /> {{ __('Generation rules') }}
    </a>
</div>
