{{-- The module's three screens, as tabs over each of them. --}}
<div role="tablist" class="tabs tabs-box w-fit">
    <a role="tab" href="{{ route('admin.privacy.requests.index') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.privacy.requests.*')])>{{ __('Requests') }}</a>
    <a role="tab" href="{{ route('admin.privacy.consents') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.privacy.consents')])>{{ __('Record of consents') }}</a>
    <a role="tab" href="{{ route('admin.privacy.settings') }}" @class(['tab', 'tab-active' => request()->routeIs('admin.privacy.settings')])>{{ __('Settings') }}</a>
</div>
