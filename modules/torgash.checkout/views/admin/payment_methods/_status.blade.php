{{-- One method's on/off, switched in place — the categories list's control. --}}
<td id="payment-method-status-{{ $method->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" @class(['badge badge-sm gap-1 cursor-pointer', 'badge-success' => $method->enabled, 'badge-ghost' => ! $method->enabled])
             title="{{ __('Change status') }}">
            {{ $method->enabled ? __('Enabled') : __('Disabled') }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Enabled'), 0 => __('Disabled')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.checkout.payment-methods.status', $method) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#payment-method-status-{{ $method->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $method->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
</td>
