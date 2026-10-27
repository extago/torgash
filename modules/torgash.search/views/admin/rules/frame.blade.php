{{-- What every rules screen shares, opened here and closed by frame-end: the
     heading, the tabs, the flash, and the card holding the form — "add" or
     "edit this row", posted to the right place. The fields go between.
     A rule is two or three fields, so the form opens ABOVE the list rather
     than on a page of its own: shut until "+ Add" is pressed, open when a row
     is being edited, when the last save was refused, or when the statistics
     screen sent a query over to be answered. The Alpine root opened here is
     closed at the end of list.blade.php, so the list's button reaches it. --}}
@php
    $rulesAction = $editing
        ? route('admin.search-engine.rules.update', [$kind, $editing->id])
        : route('admin.search-engine.rules.store', $kind);
    $rulesOpen = $editing || $errors->any() || request()->filled('phrases') || request()->filled('phrase');
    $rulesAddLabel = ['synonyms' => __('New synonym'), 'exclusions' => __('New exclusion'), 'phrases' => __('New phrase')][$kind];
@endphp
@include('torgash.search::admin.header', ['searchTab' => $kind])

@if (session('status'))
    <div class="alert alert-success">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert alert-error flex-col items-start gap-1 text-sm">
        @foreach ($errors->all() as $rulesError)
            <span>{{ $rulesError }}</span>
        @endforeach
    </div>
@endif

<div class="flex flex-col gap-4" x-data="{ adding: @js($rulesOpen) }">
<section class="card bg-base-100 border border-base-300" x-show="adding" x-cloak id="rule-form">
    <form method="POST" action="{{ $rulesAction }}" class="card-body gap-4">
        @csrf
        @if ($editing) @method('PUT') @endif
        <div class="flex items-center gap-3">
            <h3 class="font-semibold flex-1">{{ $editing ? __('Edit rule') : $rulesAddLabel }}</h3>
            <label class="label cursor-pointer gap-2">
                <input type="checkbox" name="enabled" value="1" class="toggle toggle-sm toggle-success" @checked(old('enabled', $editing?->enabled ?? true))>
                <span>{{ __('Rule works') }}</span>
            </label>
        </div>
