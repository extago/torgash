@props([
    // The form's key in `core.verified_forms`.
    'form',
    // The name of the form's own input for each kind, when it is not simply
    // "phone" / "email".
    'fields' => [],
    // What the page prefilled, per kind: ['phone' => …, 'email' => …].
    'values' => [],
])

{{--
    "Confirm the contact by a code" (App\Core\Verification\FormVerification),
    placed inside a public form under its contact fields. Draws nothing — not
    even its wrapper — unless the shop asks this form to confirm; otherwise the
    button, then the code, then a tick, each step a fragment from
    ContactVerifyController. Every button is type="button" and every request
    includes only its own form ("closest form"), so two forms on one page
    never read each other's phone.
--}}
@php
    $cvForms = app(\App\Core\Verification\FormVerification::class);
    $cvKind = $cvForms->required($form) ? $cvForms->kindFor($form) : null;
@endphp

@if ($cvKind)
    @php
        [$cvPicked, $cvAddress] = $cvForms->pick($form, $values);
        $cvKind = $cvPicked ?? $cvKind;
        $cvField = (string) json_encode((object) $fields);
        $cvDone = $cvAddress !== null && $cvForms->verified(request(), $cvKind, $cvAddress);
    @endphp
    <div {{ $attributes }}>
        @include('components.contact-verify-state', [
            'form' => $form,
            'field' => $cvField,
            'state' => $cvDone ? 'done' : 'idle',
            'kind' => $cvKind,
            'address' => $cvAddress,
            'error' => null,
            'wait' => 0,
        ])
    </div>
@endif
