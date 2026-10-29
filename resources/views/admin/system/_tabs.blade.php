{{-- The two system screens as the quiet underlined tabs under the heading,
     the way every module's screens are reached. --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.system') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.system')])>{{ __('Summary') }}</a>
    <a role="tab" href="{{ route('admin.system.backups') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.system.backups*')])>{{ __('Backups') }}</a>
</div>
