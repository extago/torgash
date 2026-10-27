{{-- ServerCheck's rows: ✓ ready, ✗ a requirement that stops the install, ! a
     recommendation. What needs attention is listed open; the passed rows are
     folded away — thirty green lines bury the one that matters. --}}
@php
    $checksFailed = collect($rows)->filter(fn ($row) => ! $row['ok'])->values();
    $checksPassed = collect($rows)->filter(fn ($row) => $row['ok'])->values();
    $checksBlocked = $checksFailed->contains(fn ($row) => $row['required']);
@endphp
<div @class(['alert text-sm py-2', 'alert-success' => $checksFailed->isEmpty(), 'alert-warning' => $checksFailed->isNotEmpty() && ! $checksBlocked, 'alert-error' => $checksBlocked])>
    <x-icon :name="$checksBlocked ? 'circle-x' : ($checksFailed->isEmpty() ? 'circle-check' : 'triangle-alert')" />
    <span>{{ $checksBlocked ? __('Not ready yet — fix what is marked below.') : ($checksFailed->isEmpty() ? __('Everything is in place.') : __('Ready; a few things are recommended.')) }}</span>
</div>
@if ($checksFailed->isNotEmpty())
    <ul class="text-sm flex flex-col gap-1">
        @foreach ($checksFailed as $row)
            <li class="flex items-start gap-2">
                <x-icon :name="$row['required'] ? 'x' : 'circle-alert'" size="sm" @class(['mt-0.5 shrink-0', 'text-error' => $row['required'], 'text-warning' => ! $row['required']]) />
                <span>{{ $row['label'] }}@if ($row['detail'] !== '')<span class="opacity-60 break-all"> — {{ $row['detail'] }}</span>@endif</span>
            </li>
        @endforeach
    </ul>
@endif
@if ($checksPassed->isNotEmpty())
    <details class="text-sm">
        <summary class="cursor-pointer opacity-60">{{ __('Passed: :count', ['count' => $checksPassed->count()]) }}</summary>
        <ul class="flex flex-col gap-1 mt-2">
            @foreach ($checksPassed as $row)
                <li class="flex items-start gap-2">
                    <x-icon name="check" size="sm" class="mt-0.5 shrink-0 text-success" />
                    <span>{{ $row['label'] }}@if ($row['detail'] !== '')<span class="opacity-60 break-all"> — {{ $row['detail'] }}</span>@endif</span>
                </li>
            @endforeach
        </ul>
    </details>
@endif
