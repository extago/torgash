{{-- One state of the contact-verify widget: idle / code / done (ContactVerifyController). --}}
@php
    // $field is the JSON map of the form's own field names (ContactVerifyController::read).
    $cvVals = json_encode(['verify_form' => $form, 'verify_fields' => $field], JSON_HEX_APOS | JSON_HEX_QUOT);
    // Whole sentences per kind, never a word dropped into one: a noun changes
    // its case around it in half the languages a shop is written in.
    $cvDoneText = $kind === 'phone' ? __('The phone is confirmed.') : __('The email is confirmed.');
    $cvAskText = $kind === 'phone'
        ? __('The shop confirms your phone by a code before sending the form.')
        : __('The shop confirms your email by a code before sending the form.');
@endphp
<div data-contact-verify class="rounded-box border p-3 flex flex-col gap-2 text-sm {{ $state === 'done' ? 'border-success/40 bg-success/5' : 'border-base-300 bg-base-200/40' }}">
    @if ($state === 'done')
        <div class="flex items-center gap-2 text-success">
            <x-icon name="circle-check" size="sm" />
            <span>{{ $cvDoneText }}</span>
        </div>
    @else
        <div class="flex items-start gap-2">
            <x-icon name="shield-check" size="sm" class="opacity-60 mt-0.5 shrink-0" />
            <span class="opacity-80">{{ $cvAskText }}</span>
        </div>

        @if ($error)
            <div class="text-error">{{ $error }}</div>
        @endif

        @if ($state === 'code')
            <div class="opacity-80">{{ __('The code was sent to') }} <b>{{ $kind === 'phone' ? \App\Core\Support\Phones::display($address) : $address }}</b></div>
            <div class="flex gap-2">
                <input name="verify_code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus
                       class="input input-bordered grow text-center font-mono tracking-[0.4em]" placeholder="••••••"
                       x-data x-on:keydown="if ($event.key === 'Enter') { $event.preventDefault(); $el.closest('[data-contact-verify]').querySelector('[data-cv-check]').click(); }">
                <button type="button" class="btn btn-primary btn-sm btn-touch" data-cv-check
                        hx-post="{{ route('contact_verify.check') }}" hx-include="closest form" hx-vals='{!! $cvVals !!}'
                        hx-target="closest [data-contact-verify]" hx-swap="outerHTML">{{ __('Confirm') }}</button>
            </div>
            <div x-data="{ wait: {{ (int) $wait }} }" x-init="if (wait > 0) { const t = setInterval(() => { if (--wait <= 0) clearInterval(t) }, 1000) }">
                <span x-show="wait > 0" class="opacity-60">{{ __('Send again in') }} <b x-text="Math.floor(wait / 60) + ':' + String(wait % 60).padStart(2, '0')"></b></span>
                <button type="button" x-show="wait <= 0" @if ($wait > 0) style="display:none" @endif class="link link-primary"
                        hx-post="{{ route('contact_verify.send') }}" hx-include="closest form" hx-vals='{!! $cvVals !!}'
                        hx-target="closest [data-contact-verify]" hx-swap="outerHTML">{{ __('Send again') }}</button>
            </div>
        @else
            <button type="button" class="btn btn-outline btn-sm btn-touch self-start gap-1.5"
                    hx-post="{{ route('contact_verify.send') }}" hx-include="closest form" hx-vals='{!! $cvVals !!}'
                    hx-target="closest [data-contact-verify]" hx-swap="outerHTML">
                <x-icon name="send" size="sm" /> {{ __('Get a code') }}
            </button>
        @endif
    @endif
</div>
