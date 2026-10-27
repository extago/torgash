{{-- A coupon's state and its switch, in place. The badge is the COMPUTED state
     (works / not started / expired / used up / off); the switch only moves
     "off" to whatever the dates and the counter then make it. --}}
@php
    $couponStates = [
        'active' => ['badge-success', 'circle-check', __('works')],
        'scheduled' => ['badge-warning', 'clock', __('not started')],
        'expired' => ['badge-ghost', 'calendar-x', __('expired')],
        'used_up' => ['badge-ghost', 'ban', __('used up')],
        'off' => ['badge-ghost', 'power-off', __('off')],
    ];
    [$couponBadge, $couponIcon, $couponLabel] = $couponStates[$coupon->state()];
@endphp
<td id="coupon-status-{{ $coupon->id }}" class="whitespace-nowrap">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" class="badge badge-sm gap-1 cursor-pointer {{ $couponBadge }}" title="{{ __('Change status') }}">
            <x-icon :name="$couponIcon" size="sm" /> {{ $couponLabel }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Enabled'), 0 => __('Disabled')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.checkout.coupons.status', $coupon) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#coupon-status-{{ $coupon->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $coupon->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
    @if ($coupon->state() === 'scheduled')
        <div class="text-xs opacity-50 mt-0.5">{{ __('starts :date', ['date' => $coupon->starts_at->diffForHumans()]) }}</div>
    @elseif ($coupon->state() === 'active' && $coupon->expires_at)
        <div class="text-xs opacity-50 mt-0.5">{{ __('ends :date', ['date' => $coupon->expires_at->diffForHumans()]) }}</div>
    @endif
</td>
