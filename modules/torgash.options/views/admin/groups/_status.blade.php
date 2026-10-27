{{-- One option's on/off, switched in place — the categories list's control. --}}
<td id="option-group-status-{{ $group->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer', 'badge-success' => $group->enabled, 'badge-ghost' => ! $group->enabled])
             title="{{ __('Change status') }}">
            {{ $group->enabled ? __('Enabled') : __('Disabled') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Enabled'), 0 => __('Disabled')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.options.groups.status', $group) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#option-group-status-{{ $group->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $group->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
