<div role="tablist" class="tabs tabs-box w-fit">
    <a role="tab" href="{{ route('admin.system') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.system')])>{{ __('Summary') }}</a>
    <a role="tab" href="{{ route('admin.system.backups') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.system.backups*')])>{{ __('Backups') }}</a>
</div>
