{{-- The system screens as the quiet underlined tabs under the heading,
     the way every module's screens are reached. Backups are three: the
     journal, the plans that make them, the storages that keep them. --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.system') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.system')])>{{ __('Summary') }}</a>
    <a role="tab" href="{{ route('admin.system.backups') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.system.backups*')])>{{ __('Backups') }}</a>
    <a role="tab" href="{{ route('admin.system.backup-plans.index') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.system.backup-plans*')])>{{ __('Backup plans') }}</a>
    <a role="tab" href="{{ route('admin.system.backup-destinations.index') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.system.backup-destinations*')])>{{ __('Backup storages') }}</a>
</div>
