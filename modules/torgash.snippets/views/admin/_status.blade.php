{{-- Whether the code is printed on the storefront, switched in place. --}}
<td id="snippet-status-{{ $snippet->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer', 'badge-success' => $snippet->enabled, 'badge-ghost' => ! $snippet->enabled])
             title="{{ __('Change status') }}">
            {{ $snippet->enabled ? __('Printed') : __('Not printed') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Printed'), 0 => __('Not printed')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.snippets.status', $snippet) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#snippet-status-{{ $snippet->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $snippet->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
