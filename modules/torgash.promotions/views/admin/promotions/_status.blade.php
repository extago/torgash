{{-- A promotion's state and its switch, in place. The badge is the COMPUTED
     state (running / scheduled / paused / ended / off) — the switch only moves
     "off" to whatever the dates and the window then make it. --}}
@php
    $statusMeta = [
        'running' => ['badge' => 'badge-success', 'icon' => 'circle-check', 'label' => __('running')],
        'scheduled' => ['badge' => 'badge-warning', 'icon' => 'clock', 'label' => __('scheduled')],
        'paused' => ['badge' => 'badge-warning', 'icon' => 'pause', 'label' => __('paused')],
        'ended' => ['badge' => 'badge-ghost', 'icon' => 'calendar-x', 'label' => __('ended')],
        'off' => ['badge' => 'badge-ghost', 'icon' => 'power-off', 'label' => __('off')],
    ];
    $promoState = $promotion->status();
    $meta = $statusMeta[$promoState];
@endphp
<td id="promotion-status-{{ $promotion->id }}" class="whitespace-nowrap">
    <div class="dropdown dropdown-end">
        <div tabindex="0" role="button" class="badge badge-sm gap-1 cursor-pointer {{ $meta['badge'] }}" title="{{ __('Change status') }}">
            <x-icon :name="$meta['icon']" size="sm" /> {{ $meta['label'] }}
            <x-icon name="chevron-down" size="sm" />
        </div>
        <ul tabindex="0" class="dropdown-content menu menu-sm bg-base-100 rounded-box shadow-md w-40 mt-1 p-1 z-50 border border-base-300">
            @foreach ([1 => __('Enabled'), 0 => __('Disabled')] as $value => $text)
                <li>
                    <button type="button"
                            hx-patch="{{ route('admin.promotions.status', $promotion) }}"
                            hx-vals='@json(['enabled' => $value])'
                            hx-target="#promotion-status-{{ $promotion->id }}" hx-swap="outerHTML"
                            @class(['font-semibold text-primary' => (int) $promotion->enabled === $value])>
                        {{ $text }}
                    </button>
                </li>
            @endforeach
        </ul>
    </div>
    @if ($promoState === 'scheduled')
        <div class="text-xs opacity-50 mt-0.5">{{ __('starts :date', ['date' => $promotion->starts_at->diffForHumans()]) }}</div>
    @elseif ($promoState === 'paused')
        {{-- The one state where "when does it come back" is the question,
             and the dates on the row cannot answer it. --}}
        <div class="text-xs opacity-50 mt-0.5">
            {{ $promotion->opensAt()
                ? __('opens :date', ['date' => $promotion->opensAt()->diffForHumans()])
                : $promotion->describeSchedule() }}
        </div>
    @elseif ($promoState === 'running' && $promotion->countdownEndsAt())
        <div class="text-xs opacity-50 mt-0.5">{{ __('ends :date', ['date' => $promotion->countdownEndsAt()->diffForHumans()]) }}</div>
    @endif
    @if (! $promotion->scheduleWindows()->isEmpty())
        <div class="text-xs opacity-50 mt-0.5 flex items-center gap-1">
            <x-icon name="timer" size="sm" /> {{ $promotion->describeSchedule() }}
        </div>
    @endif
</td>
