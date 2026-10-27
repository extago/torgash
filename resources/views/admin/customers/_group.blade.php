{{-- A customer's group, switched in place — it is what decides their prices. --}}
<td id="customer-group-{{ $customer->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer whitespace-nowrap', 'badge-info' => $customer->customerGroup, 'badge-ghost' => ! $customer->customerGroup])
             title="{{ __('Change group') }}">
            {{ $customer->customerGroup?->t('name', admin_content_locale()) ?? __('No group') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-56 mt-1 p-1 z-50 border border-base-300">
            <li>
                <button type="button"
                        hx-patch="{{ route('admin.customers.group', $customer) }}"
                        hx-vals='@json(['customer_group_id' => ''])'
                        hx-target="#customer-group-{{ $customer->id }}" hx-swap="outerHTML"
                        @class(['font-semibold text-primary' => ! $customer->customer_group_id])>
                    {{ __('No group') }}
                </button>
            </li>
            @foreach ($groups as $group)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.customers.group', $customer) }}"
                            hx-vals='@json(['customer_group_id' => $group->id])'
                            hx-target="#customer-group-{{ $customer->id }}" hx-swap="outerHTML"
                            @class(['justify-between', 'font-semibold text-primary' => (int) $customer->customer_group_id === $group->id])>
                        <span>{{ $group->t('name', admin_content_locale()) }}</span>
                        @if ($group->discount_percent > 0)
                            <span class="opacity-50">−{{ $group->discount_percent }}%</span>
                        @endif
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
