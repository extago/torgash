{{-- One page's published / draft, switched in place — the categories list's control. --}}
<td id="page-status-{{ $page->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer', 'badge-success' => $page->enabled, 'badge-ghost' => ! $page->enabled])
             title="{{ __('Change status') }}">
            {{ $page->enabled ? __('Published') : __('Draft') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Published'), 0 => __('Draft')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.pages.status', $page) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#page-status-{{ $page->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $page->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
