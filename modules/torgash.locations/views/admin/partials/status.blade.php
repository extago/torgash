{{-- On/off switched in place — the categories list's control, over the
     module's own toggle endpoints (they flip and answer with the whole row),
     so only the OTHER state posts; choosing the current one changes nothing.
     $enabled, $toggleUrl, $target (the row's id selector). --}}
<td>
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer', 'badge-success' => $enabled, 'badge-ghost' => ! $enabled])
             title="{{ __('Change status') }}">
            {{ $enabled ? __('Enabled') : __('Disabled') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Enabled'), 0 => __('Disabled')] as $value => $text)
                <li>
                    @if ((bool) $value === (bool) $enabled)
                        <button type="button" class="font-semibold text-primary">{{ $text }}</button>
                    @else
                        <button type="button" hx-post="{{ $toggleUrl }}" hx-target="{{ $target }}" hx-swap="outerHTML">{{ $text }}</button>
                    @endif
                </li>
            @endforeach
        </ul>
    </div>
</td>
