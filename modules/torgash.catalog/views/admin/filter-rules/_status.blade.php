{{-- Whether a run uses the rule, switched in place. Its pages stay either way. --}}
<td id="filter-rule-status-{{ $rule->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer whitespace-nowrap', 'badge-success' => $rule->enabled, 'badge-ghost' => ! $rule->enabled])
             title="{{ __('Change status') }}">
            {{ $rule->enabled ? __('Enabled') : __('Disabled') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Enabled'), 0 => __('Disabled')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.catalog.filter-rules.status', $rule) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#filter-rule-status-{{ $rule->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $rule->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
