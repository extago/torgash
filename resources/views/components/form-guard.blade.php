@props(['form' => '', 'addedOnly' => false, 'box' => null])

{{--
    Everything a public form has to carry to be guarded — in one place.

    These six lines were copied into five templates, which is five chances to
    forget one and ship a form with no honeypot. They are also where a CAPTCHA
    has to appear, and adding it to five templates by hand would have been the
    same mistake a second time.

    `form` is the name the guard counts under (`enquiry`, `comment`,
    `one_click`) — the same string `FormGuard::check()` is given, and the same
    one the CAPTCHA settings switch on per form. A form that passes nothing
    still gets the honeypot and the stamp; it just shares one rate-limit bucket
    with every other unnamed form, which is why every real form names itself.

    `added-only` draws just what modules add — for the checkout, which is
    checked by FormGuard::checkAdded() and takes none of core's own layers.
    `box` puts what they add in a box of those classes, and draws no box when
    they add nothing: on a form made of cards a bare widget would float.
--}}

@php
    use App\Core\Support\FormGuard;

    $guard = app(FormGuard::class);
    $formGuardAdded = (string) hook_filter('core.form.fields', '', ['form' => $form]);
@endphp

@unless ($addedOnly)
{{-- How long the form was open. Encrypted, so it cannot be forged: filled in
     faster than a person can type is a bot, and older than a day is a replay. --}}
<input type="hidden" name="{{ FormGuard::STAMP }}" value="{{ $guard->stamp() }}">

{{-- The trap. Hidden from people, irresistible to something filling in every
     input it finds. Anything in it means the submission is answered as a
     success and written nowhere — a bot that learns which of its attempts
     failed is a bot that stops filling the trap. --}}
<div class="hidden" aria-hidden="true">
    <label>{{ __('Leave this field empty') }}
        <input type="text" name="{{ FormGuard::TRAP }}" tabindex="-1" autocomplete="off" value="">
    </label>
</div>
@endunless

{{-- Whatever else a module adds to this particular form — a CAPTCHA, today.
     Empty unless something answers, so a shop with no such module renders
     nothing at all. --}}
@if ($box && trim($formGuardAdded) !== '')
<div class="{{ $box }}">{!! $formGuardAdded !!}</div>
@else
{!! $formGuardAdded !!}
@endif
