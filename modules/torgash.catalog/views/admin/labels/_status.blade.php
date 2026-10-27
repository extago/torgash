{{-- One label's on/off, switched in place — the categories list's control. --}}
<td id="label-status-{{ $label->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer', 'badge-success' => $label->enabled, 'badge-ghost' => ! $label->enabled])
             title="{{ __('Change status') }}">
            {{ $label->enabled ? __('Enabled') : __('Disabled') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Enabled'), 0 => __('Disabled')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.catalog.labels.status', $label) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#label-status-{{ $label->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $label->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
