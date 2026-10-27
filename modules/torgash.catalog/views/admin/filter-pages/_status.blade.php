{{-- Whether the address answers, switched in place. Off takes the address down. --}}
<td id="filter-page-status-{{ $page->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer whitespace-nowrap', 'badge-success' => $page->enabled, 'badge-ghost' => ! $page->enabled])
             title="{{ __('Change status') }}">
            {{ $page->enabled ? __('Published') : __('Unpublished') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-44 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Published'), 0 => __('Unpublished')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.catalog.filter-pages.status', $page) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#filter-page-status-{{ $page->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $page->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
