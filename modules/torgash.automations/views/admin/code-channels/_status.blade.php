{{-- One channel's on/off, switched in place — the categories list's control. --}}
<td id="code-channel-status-{{ $channel->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer', 'badge-success' => $channel->enabled, 'badge-ghost' => ! $channel->enabled])
             title="{{ __('Change status') }}">
            {{ $channel->enabled ? __('Enabled') : __('Disabled') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Enabled'), 0 => __('Disabled')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.automations.channels.status', $channel) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#code-channel-status-{{ $channel->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $channel->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
