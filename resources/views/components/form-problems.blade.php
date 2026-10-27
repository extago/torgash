@props(['form'])

{{--
    What is wrong with a form, said beside its button — the place the eye
    is when the press did nothing.

    Two sources, one look. What the server refused comes printed from the
    error bag; what the browser's own checks (`required`, `type=email`)
    stop is caught by form-problems.js before the browser's bubble — that
    bubble speaks the BROWSER's language, not the shop's, and points at a
    field that may sit under the sticky header. Each problem names its field
    and marks it; the first marked field is brought to the middle of the
    screen and focused.

    `form` is the id of the form — the box may stand outside it (the
    checkout's button lives in the summary column, `form=`).
--}}

@php
    $formProblemsBag = $errors ?? null;
    $formProblems = [];
    foreach ($formProblemsBag?->getMessages() ?? [] as $formProblemsField => $formProblemsMessages) {
        // One line per field, and the same sentence twice is one line.
        $formProblems[$formProblemsMessages[0]] ??= $formProblemsField;
    }
@endphp

<div data-form-problems="{{ $form }}" role="alert" aria-live="assertive"
     data-missing="{{ __('Fill in: :field') }}" data-choose="{{ __('Choose: :field') }}" data-wrong="{{ __('Check: :field') }}"
     {{ $attributes->class('torgash-problems empty:hidden') }}>@foreach ($formProblems as $formProblemsMessage => $formProblemsField)<p class="torgash-problem" data-field="{{ $formProblemsField }}"><span class="torgash-problem-icon"><x-icon name="triangle-alert" /></span><span>{{ $formProblemsMessage }}</span></p>@endforeach</div>
<template data-form-problem-template><p class="torgash-problem"><span class="torgash-problem-icon"><x-icon name="triangle-alert" /></span><span></span></p></template>

@once
    @push('scripts')
        <script nonce="{{ csp_nonce() }}" src="{{ asset_url('/assets/js/form-problems.js') }}" defer></script>
    @endpush
@endonce
