{{-- The people who run the shop and what each of them may do: one place, two screens. --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.administrators.index') }}" @class(['tab gap-2', 'tab-active' => $accessScreen === 'administrators'])>
        <x-icon name="user-cog" size="sm" /> {{ __('Administrators') }}
    </a>
    <a role="tab" href="{{ route('admin.permission-groups.index') }}" @class(['tab gap-2', 'tab-active' => $accessScreen === 'groups'])>
        <x-icon name="key-round" size="sm" /> {{ __('Permission groups') }}
    </a>
</div>
