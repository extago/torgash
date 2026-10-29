{{-- The module's three screens, as the quiet underlined tabs under the
     heading — the way every module's screens are reached; a list's own
     states are the rounded tabs over its card (app.css, `admin-tabs`). --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.privacy.requests.index') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.privacy.requests.*')])>{{ __('Requests') }}</a>
    <a role="tab" href="{{ route('admin.privacy.consents') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.privacy.consents')])>{{ __('Record of consents') }}</a>
    <a role="tab" href="{{ route('admin.privacy.settings') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.privacy.settings')])>{{ __('Settings') }}</a>
</div>
