{{-- Export and import: one subject, two screens — the shipping screens'
     own arrangement. --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.exim.profiles.index') }}" @class(['tab gap-2', 'tab-active' => $eximScreen === 'export'])>
        <x-icon name="download" size="sm" /> {{ __('Export profiles') }}
    </a>
    <a role="tab" href="{{ route('admin.exim.import.index') }}" @class(['tab gap-2', 'tab-active' => $eximScreen === 'import'])>
        <x-icon name="upload" size="sm" /> {{ __('Import') }}
    </a>
</div>
