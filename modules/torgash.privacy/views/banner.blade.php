{{--
    The cookie banner and the window of choices (PLAN §49), at the end of every
    storefront page (core.footer).

    The window is always here, closed — "#cookie-settings" anywhere on the
    site opens it again; the strip (or the corner card) only while the visitor
    has not chosen and the shop asks first. "Only the necessary" is as easy to
    press as "Accept all": a refusal hidden behind a second screen is the
    thing EU regulators fine.

    Both are ordinary forms posting to /privacy/consent, so a browser without
    scripts can still answer; banner.js sends them over fetch instead and
    switches the waiting code on in place.
--}}
@php
    $bannerPolicy = trim((string) setting('torgash.privacy', 'policy_url', ''));
    $bannerDoc = trim((string) setting('torgash.privacy', 'consent_doc_url', ''));
    $bannerCorner = setting('torgash.privacy', 'banner_style', 'bar') === 'corner';
    $bannerAllowed = $consent->allowed();
@endphp

<div data-privacy
     data-google="{{ setting('torgash.privacy', 'google_consent_mode', true) ? '1' : '0' }}"
     data-allowed="{{ implode(',', $bannerAllowed) }}">

    @if ($consent->shouldAsk())
        <section data-privacy-banner role="region" aria-label="{{ __('Cookies') }}"
                 @class([
                     'fixed z-50 bg-base-100 text-base-content border border-base-300 shadow-xl',
                     'inset-x-0 bottom-16 lg:bottom-0 border-x-0 border-b-0' => ! $bannerCorner,
                     'left-4 right-4 bottom-20 sm:right-auto sm:max-w-sm lg:bottom-4 rounded-box' => $bannerCorner,
                 ])>
            <div @class(['flex gap-3', 'container mx-auto max-w-(--torgash-container) px-(--torgash-gutter) py-4 flex-col lg:flex-row lg:items-center' => ! $bannerCorner, 'p-4 flex-col' => $bannerCorner])>
                <p class="text-sm flex-1">
                    {{ __('We use cookies: the necessary ones for the shop to work, and — only if you agree — for statistics and advertising.') }}
                    @if ($bannerPolicy)
                        <a href="{{ $bannerPolicy }}" class="link">{{ __('Privacy policy') }}</a>
                    @endif
                </p>
                <form method="POST" action="{{ route('privacy.consent') }}" data-privacy-form class="flex flex-wrap gap-2 shrink-0">
                    @csrf
                    <a href="#cookie-settings" class="btn btn-ghost btn-sm">{{ __('Choose') }}</a>
                    <button name="choice" value="necessary" class="btn btn-sm">{{ __('Only the necessary') }}</button>
                    <button name="choice" value="all" class="btn btn-primary btn-sm">{{ __('Accept all') }}</button>
                </form>
            </div>
        </section>
    @endif

    <dialog id="cookie-settings" data-privacy-dialog class="modal modal-bottom sm:modal-middle">
        <div class="modal-box flex flex-col gap-4">
            <h2 class="text-lg font-bold">{{ __('Cookie settings') }}</h2>
            <form method="POST" action="{{ route('privacy.consent') }}" data-privacy-form class="flex flex-col gap-3">
                @csrf
                <input type="hidden" name="choice" value="custom">
                @foreach (\App\Core\Privacy\Consent::CATEGORIES as $bannerCategory => $bannerLabel)
                    <label class="flex items-start gap-3 rounded-box border border-base-300 p-3 cursor-pointer">
                        @if ($bannerCategory === \App\Core\Privacy\Consent::NECESSARY)
                            <input type="checkbox" class="toggle mt-0.5" checked disabled>
                        @else
                            <input type="checkbox" name="categories[]" value="{{ $bannerCategory }}" class="toggle toggle-primary mt-0.5"
                                   @checked(in_array($bannerCategory, $bannerAllowed, true) && $consent->decided())>
                        @endif
                        <span class="flex flex-col gap-0.5">
                            <span class="font-medium text-sm">{{ __($bannerLabel) }}</span>
                            <span class="text-xs opacity-70">{{ __(\App\Core\Privacy\Consent::DESCRIPTIONS[$bannerCategory]) }}</span>
                        </span>
                    </label>
                @endforeach
                @if ($bannerPolicy || $bannerDoc)
                    <p class="text-xs opacity-70 flex flex-wrap gap-x-3">
                        @if ($bannerPolicy) <a href="{{ $bannerPolicy }}" class="link">{{ __('Privacy policy') }}</a> @endif
                        @if ($bannerDoc) <a href="{{ $bannerDoc }}" class="link">{{ __('Consent to the processing of personal data') }}</a> @endif
                    </p>
                @endif
                <div class="flex flex-wrap justify-end gap-2">
                    <button class="btn btn-sm">{{ __('Save my choice') }}</button>
                    <button name="choice" value="all" class="btn btn-primary btn-sm">{{ __('Accept all') }}</button>
                </div>
            </form>
        </div>
        <form method="dialog" class="modal-backdrop"><button>{{ __('Close') }}</button></form>
    </dialog>
</div>

@once
    <script nonce="{{ csp_nonce() }}" src="{{ module_asset('torgash.privacy', 'banner.js') }}" defer></script>
@endonce
