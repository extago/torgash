{{-- Whether customers are asked this, switched in place. --}}
<td id="shopcriterion-status-{{ $criterion->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer', 'badge-success' => $criterion->enabled, 'badge-ghost' => ! $criterion->enabled])
             title="{{ __('Change status') }}">
            {{ $criterion->enabled ? __('Asked in reviews') : __('Not asked in reviews') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Asked in reviews'), 0 => __('Not asked in reviews')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.shopreviews.criteria.status', $criterion) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#shopcriterion-status-{{ $criterion->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $criterion->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
