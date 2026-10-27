{{-- Whether the template is applied, switched in place. --}}
<td id="seo-template-status-{{ $template->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer whitespace-nowrap', 'badge-success' => $template->enabled, 'badge-ghost' => ! $template->enabled])
             title="{{ __('Change status') }}">
            {{ $template->enabled ? __('Applied') : __('Not applied') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Applied'), 0 => __('Not applied')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.seo.status', $template) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#seo-template-status-{{ $template->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $template->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
