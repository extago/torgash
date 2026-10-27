{{-- Customers and the groups they are sorted into: one place, two screens. --}}
<div role="tablist" class="tabs tabs-border">
    <a role="tab" href="{{ route('admin.customers.index') }}" @class(['tab gap-2', 'tab-active' => $customerScreen === 'customers'])>
        <x-icon name="users" size="sm" /> {{ __('Customers') }}
    </a>
    <a role="tab" href="{{ route('admin.customer-groups.index') }}" @class(['tab gap-2', 'tab-active' => $customerScreen === 'groups'])>
        <x-icon name="tag" size="sm" /> {{ __('Customer groups') }}
    </a>
</div>
