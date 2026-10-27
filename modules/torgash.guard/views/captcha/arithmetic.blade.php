{{--
    The built-in CAPTCHA, on a form.

    The picture is fetched from a route rather than inlined as a data URI, and
    that is not a detail: fetching it is what CREATES the challenge, so the
    answer is written into the session by the image request and never travels
    through this page. The nonce below says which picture — not what it says.

    "Another one" simply re-fetches the same address with a cache-buster: the
    route draws a fresh sum under the same nonce, so the old answer is replaced
    rather than left lying around.
--}}
<fieldset class="fieldset p-0">
    <legend class="fieldset-legend text-xs">{{ __('Please answer the sum') }}</legend>

    <input type="hidden" name="captcha_id" value="{{ $nonce }}">

    <div class="flex items-center gap-2 flex-wrap">
        <img id="captcha-img-{{ $nonce }}"
             src="{{ route('guard.captcha.image', ['form' => $form, 'nonce' => $nonce]) }}"
             alt="{{ __('An arithmetic problem to answer') }}"
             width="150" height="34"
             class="rounded border border-base-300 bg-white h-[34px] w-auto">

        <button type="button" class="btn btn-ghost btn-sm gap-1"
                title="{{ __('Show another one') }}"
                x-data x-on:click="const i=document.getElementById('captcha-img-{{ $nonce }}'); i.src=i.src.split('?')[0]+'?r='+Date.now();">
            <x-icon name="rotate-ccw" size="sm" />
            <span class="sr-only">{{ __('Show another one') }}</span>
        </button>

        <input type="text" name="captcha" inputmode="numeric" autocomplete="off" required
               aria-label="{{ __('The answer to the sum in the picture') }}"
               class="input input-bordered input-sm w-24">
    </div>
</fieldset>
