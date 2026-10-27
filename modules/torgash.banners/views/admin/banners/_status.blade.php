{{-- Where the banner stands — running, waiting for its date, over, off — and the switch, in place. --}}
@php
    $statusState = $banner->state();
    $statusBadge = ['running' => 'badge-success', 'scheduled' => 'badge-info', 'ended' => 'badge-warning', 'off' => 'badge-ghost'][$statusState];
    $statusText = ['running' => __('Being shown'), 'scheduled' => __('Starts later'), 'ended' => __('Ended'), 'off' => __('Off')][$statusState];
@endphp
<td id="banner-status-{{ $banner->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" class="badge badge-sm gap-1 cursor-pointer {{ $statusBadge }}" title="{{ __('Change status') }}">
            {{ $statusText }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('On'), 0 => __('Off')] as $statusValue => $statusLabel)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.banners.status', $banner) }}"
                            hx-vals='@json(['enabled' => $statusValue])'
                            hx-target="#banner-status-{{ $banner->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $banner->enabled === $statusValue])>
                        {{ $statusLabel }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
    @if ($statusState === 'scheduled')
        <div class="text-xs opacity-60 mt-1">{{ __('from :date', ['date' => $banner->starts_at->isoFormat('D MMM, HH:mm')]) }}</div>
    @elseif ($statusState === 'running' && $banner->ends_at)
        <div class="text-xs opacity-60 mt-1">{{ __('until :date', ['date' => $banner->ends_at->isoFormat('D MMM, HH:mm')]) }}</div>
    @endif
</td>
