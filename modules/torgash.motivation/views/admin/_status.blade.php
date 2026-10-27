{{-- Whether the card is shown on product pages, switched in place. --}}
<td id="motivation-status-{{ $item->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer', 'badge-success' => $item->enabled, 'badge-ghost' => ! $item->enabled])
             title="{{ __('Change status') }}">
            {{ $item->enabled ? __('Card shown') : __('Card hidden') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-36 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Card shown'), 0 => __('Card hidden')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.motivation.status', $item) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#motivation-status-{{ $item->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $item->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
