{{-- Where the landing stands — shown, waiting for its date, past it, off —
     and its switch, in place: answered by status() with this same cell. The
     badge says the STATE (on, and still waiting for its date, is a state of
     its own); the choices are on and off. --}}
@php
    $statusState = $landing->state();
    $statusBadge = ['running' => 'badge-success', 'scheduled' => 'badge-info', 'ended' => 'badge-warning', 'off' => 'badge-ghost'][$statusState];
@endphp
<td id="landing-status-{{ $landing->id }}">
    <x-admin.status-menu :label="\Torgash\Landings\Models\Landing::stateLabels()[$statusState]" :badge="$statusBadge"
                         :options="['1' => __('Switched on'), '0' => __('Switched off')]" :current="$landing->enabled ? '1' : '0'"
                         :url="route('admin.landings.status', $landing)" field="enabled" :target="'#landing-status-'.$landing->id" />
    @if ($statusState === 'scheduled')
        <div class="text-xs opacity-60 mt-1 whitespace-nowrap">{{ __('from :date', ['date' => $landing->starts_at->isoFormat('D MMM, HH:mm')]) }}</div>
    @elseif ($statusState === 'running' && $landing->ends_at)
        <div class="text-xs opacity-60 mt-1 whitespace-nowrap">{{ __('until :date', ['date' => $landing->ends_at->isoFormat('D MMM, HH:mm')]) }}</div>
    @endif
</td>
