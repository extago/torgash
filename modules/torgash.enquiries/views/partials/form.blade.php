@php
    use Torgash\Enquiries\Kinds;

    // Distinctive names on purpose: an @include inherits every variable in the
    // caller's scope, so a partial must never default an ordinarily-named one
    // (see the Blade gotcha in CLAUDE.md).
    $enquiryKind = $enquiryKind ?? 'message';
    $enquirySubjectId = $enquirySubjectId ?? null;
    $enquiryNote = $enquiryNote ?? null;
    $enquiryErrors = $enquiryErrors ?? null;
    // What was typed, when this form is being re-rendered in place after a
    // refusal. There was no redirect, so old() has nothing flashed in it —
    // and a wrong CAPTCHA answer must not cost somebody the message they
    // wrote. (LESSONS: an HTMX fragment knows neither validate() nor old().)
    $enquiryValues = $enquiryValues ?? [];
    $enquiryFields = Kinds::fields($enquiryKind);
    // A form the shop built (PLAN §48): its own button, its own "I agree".
    $enquiryForm = Kinds::find($enquiryKind)['form'] ?? null;
    // Photos ride the same POST, so the form must be multipart — and HTMX
    // told so, or it posts the words and drops the files.
    $enquiryHasFiles = in_array('file', array_column($enquiryFields, 'type'), true) && \Torgash\Enquiries\Attachments::enabled();
@endphp

{{-- hx-swap="outerHTML" on the form itself: the answer replaces this form,
     with the thank-you on success and with this same form plus its errors on
     failure — which is what keeps a mistyped phone number inside the dialog
     instead of reloading the page out from under it. Without HTMX the form is
     an ordinary POST and the page comes back with a flash message. --}}
<form method="POST" action="{{ route('enquiries.store') }}"
      hx-post="{{ route('enquiries.store') }}" hx-swap="outerHTML" hx-disabled-elt="find button[type=submit]"
      @if ($enquiryHasFiles) enctype="multipart/form-data" hx-encoding="multipart/form-data" @endif
      class="flex flex-col gap-3">
    @csrf
    <input type="hidden" name="kind" value="{{ $enquiryKind }}">
    @if ($enquirySubjectId)
        <input type="hidden" name="subject_id" value="{{ $enquirySubjectId }}">
    @endif
    <input type="hidden" name="page_url" value="{{ url()->current() }}">

    {{-- The form's age, the honeypot, and whatever else guards this form —
         one component, so no template can be shipped with half of it. --}}
    <x-form-guard form="enquiry" />

    @if ($enquiryNote)
        <p class="text-sm opacity-70">{{ $enquiryNote }}</p>
    @endif

    @php $general = $enquiryErrors?->first('enquiry') ?? $errors->first('enquiry'); @endphp
    @if ($general)
        <div class="alert alert-warning py-2 text-sm">{{ $general }}</div>
    @endif

    @foreach ($enquiryFields as $field)
        @php
            $key = $field['key'];
            $type = $field['type'];
            $label = Kinds::localized($field['label'], $key);
            $help = ! empty($field['help']) ? Kinds::localized($field['help']) : '';
            $errorKey = $type === 'file' ? 'attachments' : $key;
            $error = ($enquiryErrors?->first($errorKey) ?? $errors->first($errorKey))
                ?: ($type === 'checkboxes' ? ($enquiryErrors?->first($key.'.*') ?? $errors->first($key.'.*')) : '');
            $value = $enquiryValues[$key] ?? old($key);
        @endphp

        @continue($type === 'file' && ! $enquiryHasFiles)

        @if ($type === 'yes')
            {{-- A tick box carries its words beside it, not above. --}}
            <div class="flex flex-col gap-1">
                <label class="flex items-start gap-2 cursor-pointer text-sm">
                    <input type="checkbox" name="{{ $key }}" value="yes" class="checkbox checkbox-primary mt-0.5" @checked($value === 'yes')>
                    <span>{{ $label }}@if (! empty($field['required'])) <span class="text-error">*</span>@endif</span>
                </label>
                @if ($help)
                    <p class="text-xs opacity-60">{{ $help }}</p>
                @endif
                @if ($error)
                    <p class="text-error text-xs">{{ $error }}</p>
                @endif
            </div>
            @continue
        @endif

        <fieldset class="fieldset p-0">
            <legend class="fieldset-legend text-xs">
                {{ $label }}
                @if (! empty($field['required']))
                    <span class="text-error">*</span>
                @endif
            </legend>

            @if ($type === 'textarea')
                <textarea name="{{ $key }}" aria-label="{{ $label }}" rows="3"
                          class="textarea textarea-bordered w-full @if ($error) textarea-error @endif">{{ $value }}</textarea>
            @elseif ($type === 'select')
                <select name="{{ $key }}" aria-label="{{ $label }}" class="select select-bordered w-full">
                    {{-- An empty first choice for a form the shop built: a
                         preselected answer is an answer nobody gave. The
                         callback's "any time" keeps its own default. --}}
                    @if ($enquiryForm)
                        <option value="">—</option>
                    @endif
                    @foreach ($field['options'] ?? [] as $optionValue => $optionLabel)
                        <option value="{{ $optionValue }}" @selected((string) $value === (string) $optionValue)>
                            {{ Kinds::localized($optionLabel, $optionValue) }}
                        </option>
                    @endforeach
                </select>
            @elseif ($type === 'radio' || $type === 'checkboxes')
                <div class="flex flex-col gap-1.5">
                    @foreach ($field['options'] ?? [] as $optionValue => $optionLabel)
                        @php
                            $optionOn = $type === 'radio'
                                ? (string) $value === (string) $optionValue
                                : in_array((string) $optionValue, array_map('strval', (array) $value), true);
                        @endphp
                        <label class="flex items-center gap-2 cursor-pointer text-sm">
                            @if ($type === 'radio')
                                <input type="radio" name="{{ $key }}" value="{{ $optionValue }}" class="radio radio-primary" @checked($optionOn)>
                            @else
                                <input type="checkbox" name="{{ $key }}[]" value="{{ $optionValue }}" class="checkbox checkbox-primary" @checked($optionOn)>
                            @endif
                            <span>{{ Kinds::localized($optionLabel, $optionValue) }}</span>
                        </label>
                    @endforeach
                </div>
            @elseif ($type === 'file')
                <input type="file" name="attachments[]" aria-label="{{ $label }}" multiple accept="image/*"
                       class="file-input file-input-bordered w-full @if ($error) file-input-error @endif">
                <p class="text-xs opacity-50">{{ __('Up to :n photos.', ['n' => \Torgash\Enquiries\Attachments::maxFiles()]) }}</p>
            @else
                @php
                    [$inputType, $autocomplete] = match ($type) {
                        'tel' => ['tel', 'tel'],
                        'email' => ['email', 'email'],
                        'number' => ['number', 'off'],
                        'date' => ['date', 'off'],
                        default => ['text', $key === 'name' ? 'name' : 'off'],
                    };
                @endphp
                <input type="{{ $inputType }}" name="{{ $key }}" aria-label="{{ $label }}" value="{{ is_array($value) ? '' : $value }}"
                       autocomplete="{{ $autocomplete }}" @if ($type === 'number') step="any" @endif
                       class="input input-bordered w-full @if ($error) input-error @endif">
            @endif

            @if ($help)
                <p class="text-xs opacity-60">{{ $help }}</p>
            @endif
            @if ($error)
                <p class="label text-error text-xs">{{ $error }}</p>
            @endif
        </fieldset>
    @endforeach

    @if ($enquiryForm?->consent)
        @php
            $consentError = $enquiryErrors?->first('consent') ?? $errors->first('consent');
            $consentWords = \Torgash\Enquiries\Submit::consentText($enquiryForm);
        @endphp
        <div class="flex flex-col gap-1">
            <label class="flex items-start gap-2 cursor-pointer text-sm">
                <input type="checkbox" name="consent" value="1" class="checkbox checkbox-primary mt-0.5" @checked($enquiryValues['consent'] ?? old('consent'))>
                <span>
                    @if ($enquiryForm->consent_url)
                        <a href="{{ $enquiryForm->consent_url }}" target="_blank" rel="noopener" class="link">{{ $consentWords }}</a>
                    @else
                        {{ $consentWords }}
                    @endif
                    <span class="text-error">*</span>
                </span>
            </label>
            @if ($consentError)
                <p class="text-error text-xs">{{ $consentError }}</p>
            @endif
        </div>
    @endif

    {{-- Confirm the phone or email by a code, when the shop asks (PLAN §44). --}}
    <x-contact-verify :form="\Torgash\Enquiries\Submit::verifiedForm($enquiryKind)"
                      :values="['phone' => $enquiryValues['phone'] ?? old('phone'), 'email' => $enquiryValues['email'] ?? old('email')]" />

    <button type="submit" class="btn btn-primary">{{ $enquiryForm?->t('button') ?: __('Send') }}</button>
</form>
