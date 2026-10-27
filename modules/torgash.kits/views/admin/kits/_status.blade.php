{{-- One kit's on/off, switched in place. The badge says more than on/off,
     because a kit has dates: switched on but outside them it is offered to
     nobody, and the list must not say "on offer" about it. --}}
@php
    $kitNow = now();
    [$kitBadge, $kitWord] = match (true) {
        ! $kit->enabled => ['badge-ghost', __('Disabled')],
        $kit->starts_at && $kit->starts_at->greaterThan($kitNow) => ['badge-warning', __('Not started yet')],
        $kit->ends_at && $kit->ends_at->lessThan($kitNow) => ['badge-ghost', __('Ended')],
        default => ['badge-success', __('On offer')],
    };
@endphp
<td id="kit-status-{{ $kit->id }}">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" class="badge badge-sm gap-1 cursor-pointer {{ $kitBadge }}" title="{{ __('Change status') }}">
            {{ $kitWord }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Enabled'), 0 => __('Disabled')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.kits.status', $kit) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#kit-status-{{ $kit->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $kit->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
    @if ($kit->starts_at || $kit->ends_at)
        <div class="text-xs opacity-50 mt-1 whitespace-nowrap">{{ $kit->starts_at?->format('d.m.Y') ?? '…' }} — {{ $kit->ends_at?->format('d.m.Y') ?? '…' }}</div>
    @endif
</td>
