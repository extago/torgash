{{-- The line under the schedule field: what the typed expression means in
     dates, drawn again as it is typed (ProfilesController::schedule). --}}
@php
    $scheduleCron = \Torgash\Exim\Models\EximProfile::cron($scheduleTyped ?? '');
    $scheduleReadable = \Torgash\Exim\Models\EximProfile::readable($scheduleTyped ?? '');
@endphp
@if (! $scheduleReadable)
    <span class="text-error">{{ __('Cron cannot read this. Five fields: minute, hour, day of the month, month, day of the week.') }}</span>
@elseif ($scheduleCron === null)
    <span class="opacity-60">{{ __('Not by time.') }}</span>
@else
    <span class="opacity-60">{{ __('Next builds:') }}</span>
    <span class="tabular-nums">{{ collect(\Torgash\Exim\Models\EximProfile::nextRuns($scheduleCron))->map(fn ($at) => $at->format('d.m H:i'))->implode(', ') }}</span>
@endif
